<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Tenant;
use App\Services\Rag\DocumentIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_document_chunks_in_database(): void
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

        $count = $service->ingest(
            $document,
            'Laravel is a PHP framework.',
        );

        $this->assertSame(1, $count);

        $this->assertSame(
            'completed',
            $document->fresh()->status,
        );

        $this->assertDatabaseHas('document_chunks', [
            'tenant_id' => $tenant->id,
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Laravel is a PHP framework.',
        ]);
    }

    public function test_reingestion_does_not_duplicate_chunks(): void
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

        $service->ingest($document, 'First version of the document.');
        $service->ingest($document, 'Updated version of the document.');

        $this->assertSame(1, $document->chunks()->count());

        $this->assertDatabaseHas('document_chunks', [
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Updated version of the document.',
        ]);
    }
}
