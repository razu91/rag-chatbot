<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class RagService
{
    public function __construct(
        private readonly ChunkSearchService $chunkSearchService,
    ) {}

    public function answer(
        string $question,
        int|string $tenantId,
        int $limit = 5,
    ): array {
        if (trim($question) === '') {
            throw new \InvalidArgumentException(
                'Question cannot be empty.'
            );
        }

        // 1. Retrieve relevant chunks for this tenant.
        $chunks = $this->chunkSearchService->search(
            $tenantId,
            $question,
            $limit,
        );

        if ($chunks->isEmpty()) {
            return [
                'answer' => 'I could not find relevant information in your documents.',
                'sources' => [],
            ];
        }

        // 2. Build context from retrieved chunks.
        $context = $chunks->map(
            fn ($chunk) => $chunk->content
        )->implode("\n\n---\n\n");

        // 3. Ask Gemini to answer using the retrieved context.
        $apiKey = config('services.gemini.api_key');
        $model = config(
            'services.gemini.generation_model',
            'gemini-2.5-flash'
        );

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException(
                'Gemini API key is not configured.'
            );
        }

        $payload = [
            'systemInstruction' => [
                'parts' => [[
                    'text' => 'Answer only from the provided context. If the context does not contain the answer, say you cannot find the answer in the supplied documents. Do not invent facts.',
                ]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [[
                    'text' => "Context:\n{$context}\n\nQuestion:\n{$question}",
                ]],
            ]],
            'generationConfig' => [
                'temperature' => 0.2,
            ],
        ];

        $response = Http::acceptJson()
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->timeout(60)
            ->retry(
                3,
                1000,
                function ($exception, $request, $response = null) {
                    return $exception instanceof \Illuminate\Http\Client\ConnectionException
                        || ($response && in_array($response->status(), [429, 503], true));
                },
                throw: false
            )
            ->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
                $payload
            );

        if ($response->failed()) {
            \Illuminate\Support\Facades\Log::error(
                'Gemini answer generation failed',
                [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ]
            );

            throw new RuntimeException(
                'Gemini answer generation failed: '.$response->status()
            );
        }

        $answer = $response->json(
            'candidates.0.content.parts.0.text'
        );

        if (! is_string($answer) || trim($answer) === '') {
            throw new RuntimeException(
                'Gemini returned an empty answer.'
            );
        }

        return [
            'answer' => trim($answer),
            'sources' => $chunks->map(fn ($chunk) => [
                'chunk_id' => $chunk->id,
                'document_id' => $chunk->document_id,
                'content' => $chunk->content,
            ])->values()->all(),
        ];
    }
}
