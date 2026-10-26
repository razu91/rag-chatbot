<?php

namespace App\Services\Rag;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class DocumentIngestionService
{
    public function __construct(
        private readonly TextChunker $chunker,
        private readonly EmbeddingService $embeddingService,
    ) {}

    /**
     * Generate chunks and embeddings without changing existing chunks.
     *
     * @return array<int, array{
     *     chunk_index: int,
     *     content: string,
     *     embedding: array<float>
     * }>
     */
    public function prepare(string $text): array
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException(
                'Document text cannot be empty.'
            );
        }

        $chunks = $this->chunker->split($text);

        if ($chunks === []) {
            throw new InvalidArgumentException(
                'Document text produced no chunks.'
            );
        }

        $prepared = [];

        foreach ($chunks as $index => $content) {
            $prepared[] = [
                'chunk_index' => $index,
                'content' => $content,
                'embedding' => $this->embeddingService
                    ->embedDocument($content),
            ];
        }

        return $prepared;
    }

    /**
     * Replace document chunks using prepared embeddings.
     *
     * @param array<int, array{
     *     chunk_index: int,
     *     content: string,
     *     embedding: array<float>
     * }> $prepared
     */
    public function replaceChunks(
        Document $document,
        array $prepared,
    ): int {
        if ($prepared === []) {
            throw new InvalidArgumentException(
                'Prepared document chunks cannot be empty.'
            );
        }

        DB::transaction(function () use (
            $document,
            $prepared
        ): void {
            $document->chunks()->delete();

            foreach ($prepared as $chunk) {
                $vector = '['.implode(
                    ',',
                    $chunk['embedding']
                ).']';

                DB::insert(
                    <<<'SQL'
                    INSERT INTO document_chunks (
                        tenant_id,
                        document_id,
                        chunk_index,
                        content,
                        embedding,
                        created_at,
                        updated_at
                    )
                    VALUES (?, ?, ?, ?, CAST(? AS vector), NOW(), NOW())
                    SQL,
                    [
                        $document->tenant_id,
                        $document->id,
                        $chunk['chunk_index'],
                        $chunk['content'],
                        $vector,
                    ],
                );
            }
        });

        return count($prepared);
    }

    /**
     * Preserve the existing ingestion behavior for new documents.
     */
    public function ingest(Document $document, string $text): int
    {
        $document->update(['status' => 'processing']);

        try {
            $prepared = $this->prepare($text);

            $count = $this->replaceChunks($document, $prepared);

            $document->update(['status' => 'completed']);

            return $count;
        } catch (Throwable $exception) {
            $document->update(['status' => 'failed']);

            throw $exception;
        }
    }
}
