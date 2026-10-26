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
     * Split text, generate embeddings and store document chunks.
     */
    public function ingest(Document $document, string $text): int
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

        $document->update(['status' => 'processing']);

        try {
            // Generate embeddings before changing existing chunks.
            $embeddedChunks = [];

            foreach ($chunks as $index => $content) {
                $embedding = $this->embeddingService
                    ->embedDocument($content);

                $embeddedChunks[] = [
                    'tenant_id' => $document->tenant_id,
                    'document_id' => $document->id,
                    'chunk_index' => $index,
                    'content' => $content,
                    'embedding' => $embedding,
                ];
            }

            DB::transaction(function () use (
                $document,
                $embeddedChunks
            ): void {
                $document->chunks()->delete();

                foreach ($embeddedChunks as $chunk) {
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
                            $chunk['tenant_id'],
                            $chunk['document_id'],
                            $chunk['chunk_index'],
                            $chunk['content'],
                            $vector,
                        ]
                    );
                }
            });

            $document->update(['status' => 'completed']);

            return count($embeddedChunks);
        } catch (Throwable $exception) {
            $document->update(['status' => 'failed']);

            throw $exception;
        }
    }
}
