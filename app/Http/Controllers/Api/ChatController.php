<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Rag\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChatController extends Controller
{
    public function __construct(
        private readonly RagService $ragService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:4000'],
            'tenant_id' => ['required', 'integer'],
        ]);

        try {
            $result = $this->ragService->answer(
                $validated['question'],
                $validated['tenant_id'],
            );

            return response()->json($result);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to generate an answer right now.',
            ], 502);
        }
    }
}
