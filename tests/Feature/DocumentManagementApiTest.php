<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rag\DocumentIngestionService;
use App\Services\Rag\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => $slug,
            'slug' => $slug,
        ]);
    }

    private function authenticateAsTenant(Tenant $tenant): User
    {
        $user = new User;

        $user->name = 'Test User';
        $user->email = uniqid('user_', true).'@example.com';
        $user->password = 'password';
        $user->tenant_id = $tenant->id;
        $user->save();

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function createDocument(
        Tenant $tenant,
        array $attributes = [],
    ): Document {
        return Document::create(array_merge([
            'tenant_id' => $tenant->id,
            'title' => 'Laravel Guide',
            'original_name' => 'laravel.txt',
            'status' => 'completed',
        ], $attributes));
    }

    public function test_it_lists_only_documents_from_the_users_tenant(): void
    {
        $tenantA = $this->createTenant('company-a');
        $tenantB = $this->createTenant('company-b');

        $this->authenticateAsTenant($tenantA);

        $documentA = $this->createDocument($tenantA);
        $this->createDocument($tenantB, [
            'title' => 'Private Company B Guide',
        ]);

        $response = $this->getJson('/api/documents');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $documentA->id)
            ->assertJsonPath('data.0.title', 'Laravel Guide');
    }

    public function test_it_shows_a_document_from_the_users_tenant(): void
    {
        $tenant = $this->createTenant('company-a');
        $this->authenticateAsTenant($tenant);

        $document = $this->createDocument($tenant);

        $this->getJson("/api/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('document.id', $document->id)
            ->assertJsonPath('document.title', 'Laravel Guide');
    }

    public function test_it_hides_another_tenants_document(): void
    {
        $tenantA = $this->createTenant('company-a');
        $tenantB = $this->createTenant('company-b');

        $this->authenticateAsTenant($tenantA);

        $document = $this->createDocument($tenantB);

        $this->getJson("/api/documents/{$document->id}")
            ->assertNotFound();
    }

    public function test_it_deletes_a_document_and_its_file(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant('company-a');
        $this->authenticateAsTenant($tenant);

        $path = UploadedFile::fake()
            ->createWithContent('laravel.txt', 'Laravel guide content.')
            ->store('documents', 'local');

        $document = $this->createDocument($tenant, [
            'file_path' => $path,
        ]);

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
        ]);

        Storage::disk('local')->assertExists($path);

        $this->deleteJson("/api/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Document deleted successfully.',
            );

        $this->assertDatabaseMissing('documents', [
            'id' => $document->id,
        ]);

        Storage::disk('local')->assertMissing($path);
    }

    public function test_it_cannot_delete_another_tenants_document(): void
    {
        $tenantA = $this->createTenant('company-a');
        $tenantB = $this->createTenant('company-b');

        $this->authenticateAsTenant($tenantA);

        $document = $this->createDocument($tenantB);

        $this->deleteJson("/api/documents/{$document->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'tenant_id' => $tenantB->id,
        ]);
    }

    public function test_unauthenticated_users_cannot_list_documents(): void
    {
        $this->getJson('/api/documents')
            ->assertUnauthorized();
    }

    public function test_it_returns_not_found_for_a_missing_document(): void
    {
        $tenant = $this->createTenant('company-a');
        $this->authenticateAsTenant($tenant);

        $this->getJson('/api/documents/999999')
            ->assertNotFound();
    }

    public function test_deleting_a_document_also_deletes_its_chunks(): void
    {
        $tenant = $this->createTenant('company-a');
        $this->authenticateAsTenant($tenant);

        $document = $this->createDocument($tenant);

        $chunk = DocumentChunk::create([
            'tenant_id' => $tenant->id,
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'This is a test document chunk.',
            'metadata' => [],
        ]);

        $this->assertDatabaseHas('document_chunks', [
            'id' => $chunk->id,
            'document_id' => $document->id,
        ]);

        $this->deleteJson("/api/documents/{$document->id}")
            ->assertOk();

        $this->assertDatabaseMissing('documents', [
            'id' => $document->id,
        ]);

        $this->assertDatabaseMissing('document_chunks', [
            'id' => $chunk->id,
        ]);
    }

    public function test_unauthenticated_users_cannot_show_or_delete_documents(): void
    {
        $this->getJson('/api/documents/1')
            ->assertUnauthorized();

        $this->deleteJson('/api/documents/1')
            ->assertUnauthorized();

        $this->patchJson('/api/documents/1', [
            'title' => 'Unauthorized Update',
        ])->assertUnauthorized();
    }

    public function test_user_without_tenant_cannot_manage_documents(): void
    {
        $tenant = $this->createTenant('company-a');
        $document = $this->createDocument($tenant);

        $user = new User;
        $user->name = 'Tenantless User';
        $user->email = uniqid('tenantless_', true).'@example.com';
        $user->password = 'password';
        $user->save();

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/documents')
            ->assertForbidden();

        $this->getJson("/api/documents/{$document->id}")
            ->assertForbidden();

        $this->deleteJson("/api/documents/{$document->id}")
            ->assertForbidden();

        $this->patchJson("/api/documents/{$document->id}", [
            'title' => 'Unauthorized Update',
        ])->assertForbidden();

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_it_updates_a_document_title_without_reingestion(): void
    {
        $tenant = $this->createTenant('company-a');
        $this->authenticateAsTenant($tenant);

        $document = $this->createDocument($tenant, [
            'title' => 'Old Title',
            'status' => 'completed',
        ]);

        $this->mock(
            DocumentIngestionService::class,
            function ($mock): void {
                $mock->shouldNotReceive('prepare');
                $mock->shouldNotReceive('replaceChunks');
                $mock->shouldNotReceive('ingest');
            }
        );

        $this->patchJson("/api/documents/{$document->id}", [
            'title' => 'Updated Title',
        ])
            ->assertOk()
            ->assertJsonPath('document.title', 'Updated Title');

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'title' => 'Updated Title',
            'status' => 'completed',
        ]);
    }

    public function test_it_cannot_update_another_tenants_document(): void
    {
        $tenantA = $this->createTenant('company-a');
        $tenantB = $this->createTenant('company-b');

        $this->authenticateAsTenant($tenantA);

        $document = $this->createDocument($tenantB, [
            'title' => 'Private Document',
        ]);

        $this->patchJson("/api/documents/{$document->id}", [
            'title' => 'Modified by another tenant',
        ])->assertNotFound();

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'tenant_id' => $tenantB->id,
            'title' => 'Private Document',
        ]);
    }

    public function test_it_validates_document_update_title(): void
    {
        $tenant = $this->createTenant('company-a');
        $this->authenticateAsTenant($tenant);

        $document = $this->createDocument($tenant);

        $this->patchJson("/api/documents/{$document->id}", [
            'title' => str_repeat('a', 256),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_it_replaces_a_document_file_and_reingests_its_content(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant('company-a');
        $this->authenticateAsTenant($tenant);

        $oldPath = 'documents/old-laravel.txt';

        Storage::disk('local')->put(
            $oldPath,
            'Old document content.',
        );

        $document = $this->createDocument($tenant, [
            'title' => 'Old Title',
            'file_path' => $oldPath,
            'original_name' => 'old-laravel.txt',
            'mime_type' => 'text/plain',
            'status' => 'completed',
        ]);

        $service = $this->mock(
            DocumentIngestionService::class,
        );

        $preparedChunks = [
            [
                'chunk_index' => 0,
                'content' => 'New document content.',
                'embedding' => array_fill(0, 1536, 0.1),
            ],
        ];

        $service->shouldReceive('prepare')
            ->once()
            ->with('New document content.')
            ->andReturn($preparedChunks);

        $service->shouldReceive('replaceChunks')
            ->once()
            ->withArgs(function ($updatedDocument, $chunks) use (
                $document,
                $preparedChunks,
            ): bool {
                return $updatedDocument->id === $document->id
                    && $chunks === $preparedChunks;
            })
            ->andReturn(1);

        $file = UploadedFile::fake()->createWithContent(
            'new-laravel.txt',
            'New document content.',
        );

        $this->patch("/api/documents/{$document->id}", [
            'title' => 'Updated Title',
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('document.title', 'Updated Title')
            ->assertJsonPath('document.original_name', 'new-laravel.txt')
            ->assertJsonPath('document.status', 'completed');

        $updatedDocument = $document->fresh();

        $this->assertSame('Updated Title', $updatedDocument->title);
        $this->assertSame('new-laravel.txt', $updatedDocument->original_name);
        $this->assertNotSame($oldPath, $updatedDocument->file_path);

        Storage::disk('local')->assertExists($updatedDocument->file_path);
        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_it_preserves_the_existing_document_when_reingestion_fails(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant('company-a');
        $this->authenticateAsTenant($tenant);

        $oldPath = 'documents/old-laravel.txt';

        Storage::disk('local')->put(
            $oldPath,
            'Old document content.',
        );

        $document = $this->createDocument($tenant, [
            'title' => 'Original Title',
            'file_path' => $oldPath,
            'original_name' => 'old-laravel.txt',
            'mime_type' => 'text/plain',
            'status' => 'completed',
        ]);

        $chunk = DocumentChunk::create([
            'tenant_id' => $tenant->id,
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Existing chunk content.',
            'metadata' => [],
        ]);

        $service = $this->mock(
            DocumentIngestionService::class,
        );

        $service->shouldReceive('prepare')
            ->once()
            ->with('Replacement content.')
            ->andThrow(new \RuntimeException(
                'Embedding generation failed.',
            ));

        $service->shouldNotReceive('replaceChunks');

        $file = UploadedFile::fake()->createWithContent(
            'replacement.txt',
            'Replacement content.',
        );

        $this->patch("/api/documents/{$document->id}", [
            'title' => 'Changed Title',
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ])->assertStatus(502);

        $document->refresh();

        $this->assertSame('Original Title', $document->title);
        $this->assertSame($oldPath, $document->file_path);
        $this->assertSame('old-laravel.txt', $document->original_name);
        $this->assertSame('completed', $document->status);

        $this->assertDatabaseHas('document_chunks', [
            'id' => $chunk->id,
            'content' => 'Existing chunk content.',
        ]);

        Storage::disk('local')->assertExists($oldPath);
    }

    public function test_file_replacement_persists_new_chunks_in_the_database(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant('company-a');
        $this->authenticateAsTenant($tenant);

        $oldPath = 'documents/old-file.txt';

        Storage::disk('local')->put(
            $oldPath,
            'Old document content.',
        );

        $document = $this->createDocument($tenant, [
            'file_path' => $oldPath,
            'original_name' => 'old-file.txt',
            'status' => 'completed',
        ]);

        $oldChunk = DocumentChunk::create([
            'tenant_id' => $tenant->id,
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Old chunk content.',
            'metadata' => [],
        ]);

        $embeddingService = $this->mock(
            EmbeddingService::class,
        );

        $embeddingService->shouldReceive('embedDocument')
            ->once()
            ->with('Replacement document content.')
            ->andReturn(array_fill(0, 1536, 0.1));

        $file = UploadedFile::fake()->createWithContent(
            'replacement.txt',
            'Replacement document content.',
        );

        $this->patch("/api/documents/{$document->id}", [
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath(
                'document.original_name',
                'replacement.txt',
            );

        $this->assertDatabaseMissing('document_chunks', [
            'id' => $oldChunk->id,
        ]);

        $this->assertDatabaseHas('document_chunks', [
            'tenant_id' => $tenant->id,
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Replacement document content.',
        ]);

        $this->assertDatabaseCount('document_chunks', 1);

        $updatedDocument = $document->fresh();

        Storage::disk('local')->assertExists(
            $updatedDocument->file_path,
        );

        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_it_preserves_existing_data_when_chunk_replacement_fails(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant('company-a');
        $this->authenticateAsTenant($tenant);

        $oldPath = 'documents/old-file.txt';

        Storage::disk('local')->put(
            $oldPath,
            'Original document content.',
        );

        $document = $this->createDocument($tenant, [
            'title' => 'Original Title',
            'file_path' => $oldPath,
            'original_name' => 'old-file.txt',
            'mime_type' => 'text/plain',
            'status' => 'completed',
        ]);

        $oldChunk = DocumentChunk::create([
            'tenant_id' => $tenant->id,
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Original chunk content.',
            'metadata' => [],
        ]);

        $preparedChunks = [
            [
                'chunk_index' => 0,
                'content' => 'Replacement document content.',
                'embedding' => array_fill(0, 1536, 0.1),
            ],
        ];

        $service = $this->mock(DocumentIngestionService::class);

        $service->shouldReceive('prepare')
            ->once()
            ->with('Replacement document content.')
            ->andReturn($preparedChunks);

        $service->shouldReceive('replaceChunks')
            ->once()
            ->andThrow(new \RuntimeException(
                'Simulated chunk database failure.',
            ));

        $file = UploadedFile::fake()->createWithContent(
            'replacement.txt',
            'Replacement document content.',
        );

        $this->patch("/api/documents/{$document->id}", [
            'title' => 'Changed Title',
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ])->assertStatus(502);

        $document->refresh();

        $this->assertSame('Original Title', $document->title);
        $this->assertSame($oldPath, $document->file_path);
        $this->assertSame('old-file.txt', $document->original_name);
        $this->assertSame('completed', $document->status);

        $this->assertDatabaseHas('document_chunks', [
            'id' => $oldChunk->id,
            'content' => 'Original chunk content.',
        ]);

        Storage::disk('local')->assertExists($oldPath);

        $this->assertSame(
            [$oldPath],
            Storage::disk('local')->allFiles('documents'),
            'The failed replacement should not leave the new file behind.',
        );
    }

    public function test_it_rejects_an_empty_document_update(): void
    {
        $tenant = $this->createTenant('company-a');
        $this->authenticateAsTenant($tenant);

        $document = $this->createDocument($tenant, [
            'title' => 'Original Title',
            'status' => 'completed',
        ]);

        $this->patchJson("/api/documents/{$document->id}", [])
            ->assertUnprocessable();

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'title' => 'Original Title',
            'status' => 'completed',
        ]);
    }

    public function test_it_rejects_a_non_text_replacement_file(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant('company-a');
        $this->authenticateAsTenant($tenant);

        $oldPath = 'documents/original.txt';

        Storage::disk('local')->put(
            $oldPath,
            'Original document content.',
        );

        $document = $this->createDocument($tenant, [
            'title' => 'Original Title',
            'file_path' => $oldPath,
            'original_name' => 'original.txt',
            'status' => 'completed',
        ]);

        $file = UploadedFile::fake()->createWithContent(
            'malicious.pdf',
            'This is not a text document.',
        );

        $this->patch("/api/documents/{$document->id}", [
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $document->refresh();

        $this->assertSame('Original Title', $document->title);
        $this->assertSame($oldPath, $document->file_path);
        $this->assertSame('original.txt', $document->original_name);

        Storage::disk('local')->assertExists($oldPath);
        $this->assertSame(
            [$oldPath],
            Storage::disk('local')->allFiles('documents'),
        );
    }

    public function test_it_rejects_an_empty_replacement_file_and_preserves_existing_data(): void
    {
        Storage::fake('local');

        $tenant = $this->createTenant('company-a');
        $this->authenticateAsTenant($tenant);

        $oldPath = 'documents/original.txt';

        Storage::disk('local')->put(
            $oldPath,
            'Original document content.',
        );

        $document = $this->createDocument($tenant, [
            'title' => 'Original Title',
            'file_path' => $oldPath,
            'original_name' => 'original.txt',
            'mime_type' => 'text/plain',
            'status' => 'completed',
        ]);

        $oldChunk = DocumentChunk::create([
            'tenant_id' => $tenant->id,
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Original chunk content.',
            'metadata' => [],
        ]);

        $file = UploadedFile::fake()->createWithContent(
            'empty.txt',
            '',
        );

        $this->patch("/api/documents/{$document->id}", [
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Document text cannot be empty.',
            );

        $document->refresh();

        $this->assertSame('Original Title', $document->title);
        $this->assertSame($oldPath, $document->file_path);
        $this->assertSame('original.txt', $document->original_name);
        $this->assertSame('completed', $document->status);

        $this->assertDatabaseHas('document_chunks', [
            'id' => $oldChunk->id,
            'content' => 'Original chunk content.',
        ]);

        Storage::disk('local')->assertExists($oldPath);

        $this->assertSame(
            [$oldPath],
            Storage::disk('local')->allFiles('documents'),
            'The empty replacement file should be deleted.',
        );
    }
}
