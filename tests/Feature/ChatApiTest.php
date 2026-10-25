<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Rag\RagService;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    private function authenticatedUser(
        ?int $tenantId = 1
    ): User {
        $user = new User();

        $user->id = 100;
        $user->tenant_id = $tenantId;

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_unauthenticated_user_cannot_access_chat_api(): void
    {
        $response = $this->postJson('/api/chat', [
            'question' => 'What is Laravel?',
            'tenant_id' => 1,
        ]);

        $response->assertUnauthorized();
    }

    public function test_chat_api_returns_answer_and_sources(): void
    {
        $this->authenticatedUser(1);

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
        ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'answer',
                'Laravel is a PHP framework.'
            )
            ->assertJsonPath('sources.0.document_id', 1);
    }

    public function test_chat_api_uses_authenticated_users_tenant(): void
    {
        $this->authenticatedUser(1);

        $this->mock(RagService::class, function ($mock) {
            $mock->shouldReceive('answer')
                ->once()
                ->with('What is Laravel?', 1)
                ->andReturn([
                    'answer' => 'Laravel is a PHP framework.',
                    'sources' => [],
                ]);
        });

        $response = $this->postJson('/api/chat', [
            'question' => 'What is Laravel?',
            'tenant_id' => 999,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'answer',
                'Laravel is a PHP framework.'
            );
    }

    public function test_chat_api_rejects_missing_question(): void
    {
        $this->authenticatedUser(1);

        $response = $this->postJson('/api/chat', [
            'tenant_id' => 1,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('question');
    }

    public function test_chat_api_rejects_user_without_tenant(): void
    {
        $this->authenticatedUser(null);

        $response = $this->postJson('/api/chat', [
            'question' => 'What is Laravel?',
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Your account is not associated with a tenant.'
            );
    }
}
