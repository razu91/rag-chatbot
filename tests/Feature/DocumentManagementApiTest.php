<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rag\DocumentIngestionService;
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

}
