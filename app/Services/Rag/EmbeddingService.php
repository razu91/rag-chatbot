<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class EmbeddingService
{
    /**
     * Generate an embedding for a document chunk.
     *
     * @return array<float>
     */
    public function embedDocument(string $text): array
    {
        return $this->embed($text, 'RETRIEVAL_DOCUMENT');
    }

    /**
     * Generate an embedding for a search query.
     *
     * @return array<float>
     */
    public function embedQuery(string $text): array
    {
        return $this->embed($text, 'RETRIEVAL_QUERY');
    }

    /**
     * @return array<float>
     */
    private function embed(string $text, string $taskType): array
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException(
                'Text cannot be empty.'
            );
        }

        $apiKey = config('services.gemini.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException(
                'Gemini API key is not configured.'
            );
        }

        $model = config(
            'services.gemini.embedding_model',
            'gemini-embedding-001'
        );

        $dimensions = (int) config(
            'services.gemini.embedding_dimensions',
            1536
        );

        $response = Http::acceptJson()
            ->withHeaders([
                'x-goog-api-key' => $apiKey,
            ])
            ->timeout(30)
            ->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:embedContent",
                [
                    'content' => [
                        'parts' => [
                            ['text' => $text],
                        ],
                    ],
                    'taskType' => $taskType,
                    'outputDimensionality' => $dimensions,
                ]
            );

        if ($response->failed()) {
            throw new RuntimeException(
                'Gemini embedding request failed: '
                . $response->status()
                . ' - '
                . $response->json(
                    'error.message',
                    'Unknown API error'
                )
            );
        }

        $values = $response->json('embedding.values');

        if (! is_array($values) || count($values) !== $dimensions) {
            throw new RuntimeException(
                'Invalid embedding dimension. Expected '
                . $dimensions
                . ' values.'
            );
        }

        foreach ($values as $value) {
            if (
                ! is_numeric($value)
                || ! is_finite((float) $value)
            ) {
                throw new RuntimeException(
                    'Gemini returned an invalid embedding value.'
                );
            }
        }

        return array_map(
            static fn ($value): float => (float) $value,
            $values
        );
    }
}
