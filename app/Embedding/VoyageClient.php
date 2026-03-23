<?php

declare(strict_types=1);

namespace App\Embedding;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HTTP client for the Voyage AI embeddings API.
 *
 * Handles single document, batch, and asymmetric query embeddings with
 * automatic chunking for large batches and cost estimation.
 */
class VoyageClient
{
    private const API_URL = 'https://api.voyageai.com/v1/embeddings';

    private Client $client;

    public function __construct(
        private string $apiKey,
        private string $model,
        private int $dimensions,
        private int $batchSize,
        private LoggerInterface $logger,
    ) {
        $this->client = new Client([
            'timeout' => 30,
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Embed a single document string.
     *
     * @return float[]|null Vector of floats, or null on failure
     */
    public function embed(string $text): ?array
    {
        $result = $this->callApi([$text], 'document');

        return $result[0] ?? null;
    }

    /**
     * Embed a batch of document strings.
     *
     * @param string[] $texts
     *
     * @return array<int, float[]> Indexed array of vectors (same order as input)
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $allVectors = [];
        $chunks = array_chunk($texts, $this->batchSize);

        foreach ($chunks as $chunk) {
            $vectors = $this->callApi($chunk, 'document');
            if ($vectors === null) {
                // Fill with nulls for failed batch
                $allVectors = array_merge($allVectors, array_fill(0, count($chunk), null));
            } else {
                $allVectors = array_merge($allVectors, $vectors);
            }
        }

        return $allVectors;
    }

    /**
     * Embed a query string (uses asymmetric input_type for search).
     *
     * @return float[]|null
     */
    public function embedQuery(string $query): ?array
    {
        $result = $this->callApi([$query], 'query');

        return $result[0] ?? null;
    }

    /**
     * Estimate cost in USD for a given token count.
     * voyage-3.5-lite: $0.02 per 1M tokens
     */
    public function estimateCost(int $tokenCount): float
    {
        return ($tokenCount / 1_000_000) * 0.02;
    }

    /**
     * @return array<int, float[]>|null Array of vectors indexed by position, null on failure
     */
    private function callApi(array $texts, string $inputType): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->client->post(self::API_URL, [
                'json' => [
                    'input' => $texts,
                    'model' => $this->model,
                    'input_type' => $inputType,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!isset($body['data']) || !is_array($body['data'])) {
                $this->logger->error('VoyageClient: unexpected response format');

                return null;
            }

            // Sort by index to ensure order matches input
            $data = $body['data'];
            usort($data, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

            $vectors = [];
            foreach ($data as $item) {
                $vectors[] = $item['embedding'] ?? [];
            }

            $usage = $body['usage'] ?? [];
            $tokens = $usage['total_tokens'] ?? 0;
            $this->logger->debug('VoyageClient: embedded ' . count($texts) . " text(s), {$tokens} tokens");

            return $vectors;
        } catch (GuzzleException $e) {
            $this->logger->error("VoyageClient: API call failed: {$e->getMessage()}");

            return null;
        } catch (Throwable $e) {
            $this->logger->error("VoyageClient: unexpected error: {$e->getMessage()}");

            return null;
        }
    }
}
