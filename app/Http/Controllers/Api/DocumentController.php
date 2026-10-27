<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\Rag\DocumentIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DocumentController extends Controller
{
    /**
     * List documents belonging to the authenticated user's tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->tenant_id === null) {
            return response()->json([
                'message' => 'Your account is not associated with a tenant.',
            ], 403);
        }

        $documents = Document::query()
            ->where('tenant_id', $user->tenant_id)
            ->select([
                'id',
                'title',
                'original_name',
                'mime_type',
                'status',
                'created_at',
                'updated_at',
            ])
            ->latest()
            ->paginate(15);

        return response()->json($documents);
    }

    /**
     * Show a document belonging to the authenticated user's tenant.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if ($user->tenant_id === null) {
            return response()->json([
                'message' => 'Your account is not associated with a tenant.',
            ], 403);
        }

        $document = Document::query()
            ->where('tenant_id', $user->tenant_id)
            ->findOrFail($id);

        return response()->json([
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'original_name' => $document->original_name,
                'mime_type' => $document->mime_type,
                'status' => $document->status,
                'created_at' => $document->created_at,
                'updated_at' => $document->updated_at,
            ],
        ]);
    }

    /**
     * Delete a document belonging to the authenticated user's tenant.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if ($user->tenant_id === null) {
            return response()->json([
                'message' => 'Your account is not associated with a tenant.',
            ], 403);
        }

        $document = Document::query()
            ->where('tenant_id', $user->tenant_id)
            ->findOrFail($id);

        $filePath = $document->file_path;

        // Deleting the document also deletes its chunks through
        // the document_chunks foreign-key cascade.
        $document->delete();

        // Clean up the private file after deleting the database record.
        if ($filePath !== null) {
            $disk = Storage::disk('local');

            if ($disk->exists($filePath) && ! $disk->delete($filePath)) {
                Log::warning('Unable to delete a document file.', [
                    'document_id' => $id,
                    'file_path' => $filePath,
                ]);
            }
        }

        return response()->json([
            'message' => 'Document deleted successfully.',
        ]);
    }


    public function update(
        Request $request,
        int $id,
        DocumentIngestionService $ingestionService,
    ): JsonResponse {
        $user = $request->user();

        if ($user->tenant_id === null) {
            return response()->json([
                'message' => 'User is not associated with a tenant.',
            ], 403);
        }

        $document = Document::query()
            ->where('tenant_id', $user->tenant_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'file' => ['sometimes', 'required', 'file', 'mimes:txt', 'max:1024'],
        ]);

        $hasFile = $request->hasFile('file');
        $hasTitle = array_key_exists('title', $validated);

        if (! $hasFile && ! $hasTitle) {
            return response()->json([
                'message' => 'Provide a title or a replacement file.',
            ], 422);
        }

        // Title-only updates must not trigger ingestion.
        if (! $hasFile) {
            $document->update([
                'title' => $validated['title'],
            ]);

            return response()->json([
                'message' => 'Document updated successfully.',
                'document' => $document->fresh(),
            ]);
        }

        $disk = Storage::disk('local');

        // Capture the version this request intends to replace.
        $oldPath = $document->file_path;
        $newPath = null;

        try {
            $file = $request->file('file');
            $newPath = $file->store('documents', 'local');

            if ($newPath === false) {
                throw new \RuntimeException(
                    'Unable to store the replacement file.'
                );
            }

            $text = $disk->get($newPath);

            if (trim($text) === '') {
                $disk->delete($newPath);

                return response()->json([
                    'message' => 'Document text cannot be empty.',
                ], 422);
            }

            // Prepare embeddings before acquiring a database row lock.
            $prepared = $ingestionService->prepare($text);

            DB::transaction(function () use (
                $user,
                $id,
                $oldPath,
                $validated,
                $file,
                $newPath,
                $prepared,
                $ingestionService,
            ): void {
                $lockedDocument = Document::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Reject a replacement prepared against an older version.
                if ($lockedDocument->file_path !== $oldPath) {
                    throw new \RuntimeException(
                        'STALE_DOCUMENT_REPLACEMENT'
                    );
                }

                $lockedDocument->update([
                    'title' => $validated['title']
                        ?? $lockedDocument->title,
                    'file_path' => $newPath,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'status' => 'completed',
                ]);

                $ingestionService->replaceChunks(
                    $lockedDocument,
                    $prepared,
                );
            });

        } catch (Throwable $exception) {
            if ($newPath !== null) {
                $disk->delete($newPath);
            }

            if ($exception->getMessage() === 'STALE_DOCUMENT_REPLACEMENT') {
                return response()->json([
                    'message' => 'This document was changed by another request. Please retry.',
                ], 409);
            }

            Log::error('Document replacement failed.', [
                'document_id' => $id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Document replacement failed.',
            ], 502);
        }

        // Delete the previous file only after the transaction succeeds.
        if ($oldPath && $oldPath !== $newPath) {
            try {
                if (! $disk->delete($oldPath)) {
                    Log::warning('Unable to delete the previous document file.', [
                        'document_id' => $id,
                        'file_path' => $oldPath,
                    ]);
                }
            } catch (Throwable $exception) {
                Log::warning('Previous document file cleanup failed.', [
                    'document_id' => $id,
                    'file_path' => $oldPath,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Document updated successfully.',
            'document' => $document->fresh(),
        ]);
    }

}
