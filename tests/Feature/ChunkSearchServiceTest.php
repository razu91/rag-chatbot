<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Tenant;
use App\Services\Rag\ChunkSearchService;
use App\Services\Rag\DocumentIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChunkSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent real Gemini API calls in automated tests.
        Http::fake([
            '*generativelanguage.googleapis.com/*' =>
                Http::response([
                    'embedding' => [
                        'values' => array_fill(0, 1536, 0.01),
                    ],
                ], 200),
        ]);
    }

    public function test_it_searches_only_within_the_requested_tenant(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Company A',
            'slug' => 'company-a',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Company B',
            'slug' => 'company-b',
        ]);

        $documentA = Document::create([
            'tenant_id' => $tenantA->id,
            'title' => 'Laravel Guide',
            'original_name' => 'laravel.txt',
            'status' => 'pending',
        ]);

        $documentB = Document::create([
            'tenant_id' => $tenantB->id,
            'title' => 'PHP Guide',
            'original_name' => 'php.txt',
            'status' => 'pending',
        ]);

        $ingestion = app(DocumentIngestionService::class);

        $ingestion->ingest(
            $documentA,
            'Laravel is a PHP framework.',
        );

        $ingestion->ingest(
            $documentB,
            'PHP is a server-side programming language.',
        );

        $results = app(ChunkSearchService::class)->search(
            $tenantA->id,
            'What is Laravel?',
            5,
        );

        $this->assertCount(1, $results);

        $this->assertSame(
            $tenantA->id,
            $results->first()->tenant_id,
        );

        $this->assertSame(
            $documentA->id,
            $results->first()->document_id,
        );

        $this->assertNotNull($results->first()->distance);

        // Two document embeddings plus one query embedding.
        Http::assertSentCount(3);

        Http::assertSent(
            fn ($request) =>
                $request['taskType'] === 'RETRIEVAL_QUERY'
        );
    }
}
