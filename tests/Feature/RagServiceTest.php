<?php

namespace Tests\Feature;

use App\Models\DocumentChunk;
use App\Services\Rag\ChunkSearchService;
use App\Services\Rag\RagService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RagServiceTest extends TestCase
{
    public function test_it_generates_an_answer_using_retrieved_context(): void
    {
        config([
            'services.gemini.api_key' => 'test-api-key',
            'services.gemini.generation_model' => 'gemini-2.5-flash',
        ]);

        $chunk = new DocumentChunk([
            'document_id' => 7,
            'content' => 'Laravel is a PHP framework.',
        ]);

        $chunk->id = 12;

        $chunks = new Collection([$chunk]);

        $search = $this->mock(ChunkSearchService::class);

        $search->shouldReceive('search')
            ->once()
            ->with(1, 'What is Laravel?', 5)
            ->andReturn($chunks);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Laravel is a PHP framework.'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = app(RagService::class);

        $result = $service->answer(
            'What is Laravel?',
            1,
        );

        $this->assertSame(
            'Laravel is a PHP framework.',
            $result['answer'],
        );

        $this->assertSame(12, $result['sources'][0]['chunk_id']);
        $this->assertSame(7, $result['sources'][0]['document_id']);

        Http::assertSent(function ($request) {
            return str_contains(
                $request->url(),
                ':generateContent',
            )
                && str_contains(
                    $request['contents'][0]['parts'][0]['text'],
                    'Laravel is a PHP framework.',
                );
        });
    }

    public function test_it_returns_a_fallback_when_no_chunks_are_found(): void
    {
        $search = $this->mock(ChunkSearchService::class);

        $search->shouldReceive('search')
            ->once()
            ->with(1, 'Unknown question', 5)
            ->andReturn(new Collection());

        $service = app(RagService::class);

        $result = $service->answer('Unknown question', 1);

        $this->assertSame(
            'I could not find relevant information in your documents.',
            $result['answer'],
        );

        $this->assertSame([], $result['sources']);
    }
}
