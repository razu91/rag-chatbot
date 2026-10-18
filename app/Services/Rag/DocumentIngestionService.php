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
    ) {}

    /**
     * Split document text and store its chunks.
     *
     * @return int Number of chunks stored
     */
    public function ingest(Document $document, string $text): int
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException(
                'Document text cannot be empty.'
            );
        }

        $chunks = $this->chunker->split($text);

        $document->update(['status' => 'processing']);

        try {
            $count = DB::transaction(function () use ($document, $chunks) {
                // Replace existing chunks during re-ingestion.
                $document->chunks()->delete();

                foreach ($chunks as $index => $content) {
                    $document->chunks()->create([
                        'tenant_id' => $document->tenant_id,
                        'chunk_index' => $index,
                        'content' => $content,
                    ]);
                }

                return count($chunks);
            });

            $document->update(['status' => 'completed']);

            return $count;
        } catch (Throwable $exception) {
            $document->update(['status' => 'failed']);

            throw $exception;
        }
    }
}
