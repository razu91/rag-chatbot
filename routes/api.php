<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DocumentUploadController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/chat', ChatController::class)->middleware('auth:sanctum');
Route::post('/documents', DocumentUploadController::class)
    ->middleware('auth:sanctum');
