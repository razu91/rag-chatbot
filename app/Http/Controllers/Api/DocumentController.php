<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (! $user->tenant_id) {
            return response()->json([
                'message' => 'User is not associated with a tenant.',
            ], 403);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
        ]);

        if (! array_key_exists('title', $validated)) {
            return response()->json([
                'message' => 'No valid fields provided for update.',
            ], 422);
        }

        $document = Document::query()
            ->where('tenant_id', $user->tenant_id)
            ->findOrFail($id);

        $document->update([
            'title' => $validated['title'],
        ]);

        return response()->json([
            'message' => 'Document updated successfully.',
            'document' => $document->fresh(),
        ]);
    }
}
