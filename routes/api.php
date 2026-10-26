<?php

use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentUploadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/chat', ChatController::class)->middleware('auth:sanctum');
Route::post('/documents', DocumentUploadController::class)
    ->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::get('/documents/{id}', [DocumentController::class, 'show'])
        ->whereNumber('id');

    Route::patch('/documents/{id}', [
        DocumentController::class,
        'update',
    ])->whereNumber('id');

    Route::delete('/documents/{id}', [
        DocumentController::class,
        'destroy',
    ])->whereNumber('id');
});
