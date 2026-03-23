<?php

declare(strict_types=1);

namespace App\Chat;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HTTP client for the Anthropic Messages API with automatic tool_use loop handling.
 *
 * Sends messages, processes tool calls via ChatToolHandler, and tracks token usage and cost.
 */
class AnthropicClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    private Client $client;

    public function __construct(
        private string $apiKey,
        private string $model,
        private int $maxTokens,
        private float $temperature,
        private int $maxToolRounds,
        private LoggerInterface $logger,
    ) {
        $this->client = new Client([
            'timeout' => 120,
            'decode_content' => true,
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'Content-Type' => 'application/json',
                'Accept-Encoding' => 'identity',
            ],
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Send a message with tool_use loop handling.
     *
     * @param array $messages Anthropic-format messages [{role, content}]
     * @param array $tools Tool definitions array
     *
     * @return array{response: string, messages: array, usage: array{input_tokens: int, output_tokens: int, cost_usd: float}}
     */
    public function sendMessage(
        string $systemPrompt,
        array $messages,
        array $tools,
        ChatToolHandler $toolHandler,
    ): array {
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $round = 0;

        while ($round < $this->maxToolRounds) {
            $round++;

            $result = $this->callApi($systemPrompt, $messages, $tools);

            if ($result === null) {
                $errorMsg = 'I encountered an error communicating with the API. Please try again.';
                // Append assistant error message to maintain proper message alternation
                $messages[] = ['role' => 'assistant', 'content' => $errorMsg];

                return [
                    'response' => $errorMsg,
                    'messages' => $messages,
                    'usage' => $this->buildUsage($totalInputTokens, $totalOutputTokens),
                ];
            }

            $totalInputTokens += $result['usage']['input_tokens'] ?? 0;
            $totalOutputTokens += $result['usage']['output_tokens'] ?? 0;

            $stopReason = $result['stop_reason'] ?? 'end_turn';
            $content = $result['content'] ?? [];

            // Append assistant message
            $messages[] = ['role' => 'assistant', 'content' => $content];

            if ($stopReason === 'end_turn' || $stopReason === 'max_tokens') {
                // Extract text from content blocks
                $text = $this->extractText($content);

                return [
                    'response' => $text,
                    'messages' => $messages,
                    'usage' => $this->buildUsage($totalInputTokens, $totalOutputTokens),
                ];
            }

            if ($stopReason === 'tool_use') {
                $toolResults = [];
                foreach ($content as $block) {
                    if (($block['type'] ?? '') !== 'tool_use') {
                        continue;
                    }

                    $toolName = $block['name'] ?? '';
                    $toolInput = $block['input'] ?? [];
                    $toolUseId = $block['id'] ?? '';

                    $this->logger->info("ChatAPI tool_use: {$toolName}", ['input' => $toolInput]);

                    try {
                        $toolResult = $toolHandler->execute($toolName, $toolInput);
                        $toolResults[] = [
                            'type' => 'tool_result',
                            'tool_use_id' => $toolUseId,
                            'content' => json_encode($toolResult, JSON_UNESCAPED_SLASHES),
                        ];
                    } catch (Throwable $e) {
                        $this->logger->error("ChatAPI tool error: {$toolName}: {$e->getMessage()}");
                        $toolResults[] = [
                            'type' => 'tool_result',
                            'tool_use_id' => $toolUseId,
                            'is_error' => true,
                            'content' => "Error: {$e->getMessage()}",
                        ];
                    }
                }

                // Append tool results as user message
                $messages[] = ['role' => 'user', 'content' => $toolResults];
                continue;
            }

            // Unknown stop reason — treat as end
            $text = $this->extractText($content);

            return [
                'response' => $text,
                'messages' => $messages,
                'usage' => $this->buildUsage($totalInputTokens, $totalOutputTokens),
            ];
        }

        // Max tool rounds exceeded
        $this->logger->warning("ChatAPI: max tool rounds ({$this->maxToolRounds}) exceeded");

        return [
            'response' => 'I reached the maximum number of tool calls for this turn. Here\'s what I\'ve done so far — please check the task list for any created tasks.',
            'messages' => $messages,
            'usage' => $this->buildUsage($totalInputTokens, $totalOutputTokens),
        ];
    }

    private function callApi(string $systemPrompt, array $messages, array $tools): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'system' => $systemPrompt,
            'messages' => $messages,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        try {
            $response = $this->client->post(self::API_URL, [
                'json' => $payload,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!is_array($body) || !isset($body['content'])) {
                $this->logger->error('AnthropicClient: unexpected response format');

                return null;
            }

            return $body;
        } catch (GuzzleException $e) {
            $body = '';
            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $body = $e->getResponse()->getBody()->getContents();
            }
            $this->logger->error("AnthropicClient: API call failed: {$e->getMessage()}", ['response_body' => $body]);

            return null;
        } catch (Throwable $e) {
            $this->logger->error("AnthropicClient: unexpected error: {$e->getMessage()}");

            return null;
        }
    }

    private function extractText(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $parts[] = $block['text'] ?? '';
            }
        }

        return implode("\n", $parts);
    }

    private function buildUsage(int $inputTokens, int $outputTokens): array
    {
        // Pricing per million tokens (Sonnet 4 as default)
        $inputCostPerM = 3.00;
        $outputCostPerM = 15.00;

        $cost = ($inputTokens / 1_000_000) * $inputCostPerM
              + ($outputTokens / 1_000_000) * $outputCostPerM;

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => round($cost, 6),
        ];
    }
}
