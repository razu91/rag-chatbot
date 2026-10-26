<?php

namespace App\Services\Rag;

use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class ChunkSearchService
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
    ) {}

    /**
     * Search document chunks within a single tenant.
     *
     * @return Collection<int, DocumentChunk>
     */
    public function search(
        int $tenantId,
        string $query,
        int $limit = 5,
    ): Collection {
        if (trim($query) === '') {
            throw new InvalidArgumentException(
                'Search query cannot be empty.'
            );
        }

        if ($limit < 1 || $limit > 20) {
            throw new InvalidArgumentException(
                'Search limit must be between 1 and 20.'
            );
        }

        $embedding = $this->embeddingService
            ->embedQuery($query);

        $vector = '['.implode(',', $embedding).']';

        return DocumentChunk::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('embedding')
            ->select('*')
            ->selectRaw(
                'embedding <=> CAST(? AS vector) AS distance',
                [$vector]
            )
            ->orderByRaw(
                'embedding <=> CAST(? AS vector) ASC',
                [$vector]
            )
            ->limit($limit)
            ->get();
    }
}
