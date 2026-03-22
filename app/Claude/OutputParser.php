<?php

declare(strict_types=1);

namespace App\Claude;

class OutputParser
{
    /**
     * Parse the JSON output from Claude CLI.
     * Claude CLI with --output-format json returns structured JSON output.
     */
    public function parse(string $output, int $exitCode): ParsedOutput
    {
        if (trim($output) === '') {
            return ParsedOutput::fromFailure('Empty output from Claude CLI');
        }

        $decoded = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($exitCode === 0) {
                return ParsedOutput::fromSuccess($output);
            }
            return ParsedOutput::fromFailure($output);
        }

        $sessionId = $decoded['session_id'] ?? $decoded['sessionId'] ?? null;

        if (isset($decoded['error'])) {
            return ParsedOutput::fromFailure(
                is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error']),
                $decoded,
                $sessionId
            );
        }

        // Handle error_max_turns — agent ran out of turns (often due to permission denials)
        $subtype = $decoded['subtype'] ?? '';
        if ($subtype === 'error_max_turns') {
            $errorParts = ['Agent hit max turns limit (' . ($decoded['num_turns'] ?? '?') . ' turns)'];

            $denials = $decoded['permission_denials'] ?? [];
            if (!empty($denials)) {
                $deniedTools = array_unique(array_column($denials, 'tool_name'));
                $errorParts[] = 'Permission denied for: ' . implode(', ', $deniedTools);
                $errorParts[] = 'Configure CLAUDE_ALLOWED_TOOLS to grant tool access';
            }

            $cost = (float) ($decoded['total_cost_usd'] ?? 0);
            if ($cost > 0) {
                $errorParts[] = sprintf('Cost: $%.2f', $cost);
            }

            return ParsedOutput::fromFailure(implode('. ', $errorParts), $decoded, $sessionId);
        }

        $result = $this->extractResult($decoded);
        $images = $this->extractImages($decoded);

        if ($exitCode !== 0 && $result === '') {
            return ParsedOutput::fromFailure(
                $decoded['message'] ?? 'Claude CLI exited with code ' . $exitCode,
                $decoded,
                $sessionId
            );
        }

        return ParsedOutput::fromSuccess($result, $sessionId, $decoded, $images);
    }

    private function extractResult(array $data): string
    {
        if (isset($data['result']) && is_string($data['result'])) {
            return $data['result'];
        }

        if (isset($data['content']) && is_string($data['content'])) {
            return $data['content'];
        }

        if (isset($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }

        if (isset($data['content']) && is_array($data['content'])) {
            $texts = [];
            foreach ($data['content'] as $block) {
                if (isset($block['text'])) {
                    $texts[] = $block['text'];
                }
            }
            if (!empty($texts)) {
                return implode("\n", $texts);
            }
        }

        return json_encode($data, JSON_PRETTY_PRINT);
    }

    /**
     * Extract <memory> tags from Claude's output text.
     * Returns extracted memory objects and the cleaned text with tags stripped.
     *
     * @return array{memories: array, cleaned: string}
     */
    public static function extractMemoryTags(string $text): array
    {
        $memories = [];
        $pattern = '/<memory\s+category="([^"]+)"(?:\s+importance="([^"]+)")?>(.+?)<\/memory>/s';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $memories[] = [
                    'category' => $match[1],
                    'importance' => $match[2] !== '' ? $match[2] : 'normal',
                    'content' => trim($match[3]),
                ];
            }
        }

        $cleaned = preg_replace($pattern, '', $text);
        $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned);
        $cleaned = trim($cleaned);

        return ['memories' => $memories, 'cleaned' => $cleaned];
    }

    /**
     * Extract <work_item> tags from Claude's output text.
     * Returns extracted work items and the cleaned text with tags stripped.
     *
     * Tags: <work_item title="Add auth" priority="high" epic="Authentication">Description</work_item>
     * Attributes: title (required), priority (optional, default normal), epic (optional epic name).
     *
     * @return array{items: array, cleaned: string}
     */
    public static function extractWorkItemTags(string $text): array
    {
        $items = [];
        $pattern = '/<work_item\s+([^>]+)>(.+?)<\/work_item>/s';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attrString = $match[1];
                $description = trim($match[2]);

                // Parse title (required)
                if (!preg_match('/title="([^"]+)"/', $attrString, $titleMatch)) {
                    continue;
                }

                $title = $titleMatch[1];

                // Parse optional attributes
                $priority = 'normal';
                if (preg_match('/priority="([^"]+)"/', $attrString, $priorityMatch)) {
                    $priority = in_array($priorityMatch[1], ['low', 'normal', 'high', 'urgent'], true)
                        ? $priorityMatch[1]
                        : 'normal';
                }

                $epic = '';
                if (preg_match('/epic="([^"]+)"/', $attrString, $epicMatch)) {
                    $epic = $epicMatch[1];
                }

                $items[] = [
                    'title' => $title,
                    'description' => $description,
                    'priority' => $priority,
                    'epic' => $epic,
                ];
            }
        }

        $cleaned = preg_replace($pattern, '', $text);
        $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned);
        $cleaned = trim($cleaned);

        return ['items' => $items, 'cleaned' => $cleaned];
    }

    /**
     * Extract base64-encoded images from Claude CLI output content blocks.
     * Returns array of ['data' => base64, 'media_type' => 'image/png', ...].
     */
    private function extractImages(array $data): array
    {
        $images = [];

        if (isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $block) {
                if (($block['type'] ?? '') === 'image' && isset($block['source'])) {
                    $source = $block['source'];
                    if (($source['type'] ?? '') === 'base64' && isset($source['data'])) {
                        $images[] = [
                            'data' => $source['data'],
                            'media_type' => $source['media_type'] ?? 'image/png',
                        ];
                    }
                }
            }
        }

        return $images;
    }
}
