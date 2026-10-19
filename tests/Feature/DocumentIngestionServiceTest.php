<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Tenant;
use App\Services\Rag\DocumentIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Never make real Gemini API calls during tests.
        Http::fake([
            '*generativelanguage.googleapis.com/*' =>
                Http::response([
                    'embedding' => [
                        'values' => array_fill(0, 1536, 0.01),
                    ],
                ], 200),
        ]);
    }

    public function test_it_stores_chunks_and_embeddings(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        $document = Document::create([
            'tenant_id' => $tenant->id,
            'title' => 'Laravel Guide',
            'original_name' => 'laravel.txt',
            'status' => 'pending',
        ]);

        $count = app(DocumentIngestionService::class)->ingest(
            $document,
            'Laravel is a PHP framework.',
        );

        $this->assertSame(1, $count);
        $this->assertSame('completed', $document->fresh()->status);

        $this->assertDatabaseHas('document_chunks', [
            'tenant_id' => $tenant->id,
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Laravel is a PHP framework.',
        ]);

        $row = DB::selectOne(
            'SELECT embedding IS NOT NULL AS has_embedding
             FROM document_chunks
             WHERE document_id = ? AND chunk_index = ?',
            [$document->id, 0],
        );

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->has_embedding);

        Http::assertSentCount(1);
    }

    public function test_reingestion_replaces_existing_chunks(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        $document = Document::create([
            'tenant_id' => $tenant->id,
            'title' => 'Laravel Guide',
            'original_name' => 'laravel.txt',
            'status' => 'pending',
        ]);

        $service = app(DocumentIngestionService::class);

        $service->ingest(
            $document,
            'First version of the document.',
        );

        $service->ingest(
            $document,
            'Updated version of the document.',
        );

        $this->assertSame(1, $document->chunks()->count());

        $this->assertDatabaseHas('document_chunks', [
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Updated version of the document.',
        ]);

        $row = DB::selectOne(
            'SELECT embedding IS NOT NULL AS has_embedding
             FROM document_chunks
             WHERE document_id = ? AND chunk_index = ?',
            [$document->id, 0],
        );

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->has_embedding);

        Http::assertSentCount(2);
    }
}
