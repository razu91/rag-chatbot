<?php

namespace Tests\Feature;

use App\Services\Rag\RagService;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    public function test_chat_api_returns_answer_and_sources(): void
    {
        $this->mock(RagService::class, function ($mock) {
            $mock->shouldReceive('answer')
                ->once()
                ->with('What is Laravel?', 1)
                ->andReturn([
                    'answer' => 'Laravel is a PHP framework.',
                    'sources' => [
                        [
                            'chunk_id' => 10,
                            'document_id' => 1,
                            'content' => 'Laravel is a PHP framework.',
                        ],
                    ],
                ]);
        });

        $response = $this->postJson('/api/chat', [
            'question' => 'What is Laravel?',
            'tenant_id' => 1,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'answer',
                'Laravel is a PHP framework.'
            )
            ->assertJsonPath('sources.0.document_id', 1);
    }

    public function test_chat_api_rejects_missing_question(): void
    {
        $response = $this->postJson('/api/chat', [
            'tenant_id' => 1,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('question');
    }
}
