<?php

namespace App\Services\Rag;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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

        return DB::transaction(function () use ($document, $chunks) {
            // Remove old chunks so re-ingestion does not create duplicates.
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
    }
}
