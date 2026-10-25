<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\Rag\DocumentIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class DocumentUploadController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, DocumentIngestionService $ingestionService,): JsonResponse
    {
        $validated = $request->validate([

            'file' => [
                'required',
                'file',
                'mimes:txt',
                'max:1024'
            ],

            'title' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ]
        ]);

        $user = $request->user();

        if ($user->tenant_id === null) {
            return response()->json([
                'message' => 'Your account is not associated with a tenant.'
            ], 403);
        }

        $file = $validated['file'];

        try {
            // Store the uploaded file on Laravel's private local disk.
            $path = $file->store('documents', 'local');

            if ($path === false) {
                throw new RuntimeException(
                    'Unable to store the uploaded document.'
                );
            }

            $text = file_get_contents($file->getRealPath());

            if ($text === false || trim($text) === '') {
                throw new RuntimeException(
                    'The uploaded document is empty or unreadable.'
                );
            }

            $document = Document::create([
                'tenant_id' => $user->tenant_id,
                'uploaded_by' => $user->id,
                'title' => $validated['title']
                    ?? pathinfo(
                        $file->getClientOriginalName(),
                        PATHINFO_FILENAME
                    ),
                'original_name' =>
                $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getMimeType(),
                'status' => 'pending',
            ]);

            $chunksCount = $ingestionService->ingest(
                $document,
                $text,
            );

            return response()->json([
                'message' => 'Document uploaded and processed successfully.',
                'document' => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'original_name' => $document->original_name,
                    'status' => $document->fresh()->status,
                    'chunks_count' => $chunksCount,
                ],
            ], 201);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json([
                'message' =>
                'Unable to upload or process the document right now.',
            ], 502);
        }
    }
}
