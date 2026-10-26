<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rag\DocumentIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DocumentUploadApiTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(
        ?int $tenantId = null
    ): User {
        $tenant = $tenantId === null
            ? Tenant::create([
                'name' => 'Test Company',
                'slug' => 'test-company',
            ])
            : Tenant::findOrFail($tenantId);

        $user = User::create([
            'name' => 'Test User',
            'email' => uniqid('user_', true).'@example.com',
            'password' => 'password',
        ]);

        $user->tenant_id = $tenant->id;
        $user->save();

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_unauthenticated_user_cannot_upload_document(): void
    {
        $response = $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent(
                'guide.txt',
                'Laravel is a PHP framework.',
            ),
        ]);

        $response->assertUnauthorized();
    }

    public function test_it_rejects_non_text_files(): void
    {
        $this->authenticatedUser();

        $response = $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create(
                'guide.pdf',
                10,
                'application/pdf',
            ),
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_it_rejects_users_without_a_tenant(): void
    {
        $user = User::create([
            'name' => 'Tenantless User',
            'email' => 'tenantless@example.com',
            'password' => 'password',
        ]);

        $user->tenant_id = null;
        $user->save();

        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent(
                'guide.txt',
                'Laravel is a PHP framework.',
            ),
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Your account is not associated with a tenant.',
            );
    }

    public function test_it_uploads_and_processes_a_text_document(): void
    {
        Storage::fake('local');

        $user = $this->authenticatedUser();

        $this->mock(
            DocumentIngestionService::class,
            function ($mock): void {
                $mock->shouldReceive('ingest')
                    ->once()
                    ->andReturnUsing(
                        function (Document $document, string $text): int {
                            $this->assertSame(
                                'Laravel is a PHP framework.',
                                $text,
                            );

                            $document->update([
                                'status' => 'completed',
                            ]);

                            return 1;
                        }
                    );
            }
        );

        $response = $this->postJson('/api/documents', [
            'title' => 'Laravel Guide',
            'file' => UploadedFile::fake()->createWithContent(
                'laravel.txt',
                'Laravel is a PHP framework.',
            ),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Document uploaded and processed successfully.',
            )
            ->assertJsonPath('document.title', 'Laravel Guide')
            ->assertJsonPath('document.status', 'completed')
            ->assertJsonPath('document.chunks_count', 1);

        $this->assertDatabaseHas('documents', [
            'tenant_id' => $user->tenant_id,
            'uploaded_by' => $user->id,
            'title' => 'Laravel Guide',
            'original_name' => 'laravel.txt',
            'status' => 'completed',
        ]);

        $document = Document::query()
            ->where('uploaded_by', $user->id)
            ->firstOrFail();

        Storage::disk('local')->assertExists(
            $document->file_path
        );
    }

    public function test_it_returns_an_error_when_ingestion_fails(): void
    {
        Storage::fake('local');

        $this->authenticatedUser();

        $this->mock(
            DocumentIngestionService::class,
            function ($mock): void {
                $mock->shouldReceive('ingest')
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Sensitive embedding API key and database details.'
                        )
                    );
            }
        );

        $response = $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent(
                'guide.txt',
                'Laravel is a PHP framework.',
            ),
        ]);

        $response
            ->assertStatus(502)
            ->assertJsonPath(
                'message',
                'Unable to upload or process the document right now.',
            )
            ->assertDontSee(
                'Sensitive embedding API key and database details.'
            );

        $this->assertDatabaseCount('documents', 0);

        $this->assertSame(
            [],
            Storage::disk('local')->allFiles('documents')
        );
    }

    public function test_it_rejects_an_empty_text_file(): void
    {
        Storage::fake('local');

        $tenant = Tenant::create([
            'name' => 'Company A',
            'slug' => 'company-a',
        ]);

        $user = new User;
        $user->name = 'Test User';
        $user->email = uniqid('user_', true).'@example.com';
        $user->password = 'password';
        $user->tenant_id = $tenant->id;
        $user->save();

        $this->actingAs($user, 'sanctum');

        $file = UploadedFile::fake()->createWithContent(
            'empty.txt',
            ''
        );

        $response = $this->postJson('/api/documents', [
            'file' => $file,
        ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('documents', 0);

        $this->assertSame(
            [],
            Storage::disk('local')->allFiles('documents')
        );
    }

    public function test_it_rejects_files_larger_than_the_upload_limit(): void
    {
        Storage::fake('local');

        $tenant = Tenant::create([
            'name' => 'Company A',
            'slug' => 'company-a',
        ]);

        $user = new User;
        $user->name = 'Test User';
        $user->email = uniqid('user_', true).'@example.com';
        $user->password = 'password';
        $user->tenant_id = $tenant->id;
        $user->save();

        $this->actingAs($user, 'sanctum');

        $file = UploadedFile::fake()->create(
            'large.txt',
            1025,
            'text/plain'
        );

        $this->postJson('/api/documents', [
            'file' => $file,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('documents', 0);

        $this->assertSame(
            [],
            Storage::disk('local')->allFiles('documents')
        );
    }
}
