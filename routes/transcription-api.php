<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TranscriptionController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:60,1');
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Credits and payment routes
    Route::get('/credits/packages', [App\Http\Controllers\Api\PaymentController::class, 'packages']);
    Route::post('/credits/purchase', [App\Http\Controllers\Api\PaymentController::class, 'purchase']);
    Route::get('/credits/balance', [App\Http\Controllers\Api\PaymentController::class, 'balance']);
    Route::get('/credits/transactions', [App\Http\Controllers\Api\PaymentController::class, 'transactions']);
});

Route::post('/transcribe', [TranscriptionController::class, 'transcribe']);
Route::get('/transcribe/jobs/{job}', [TranscriptionController::class, 'transcriptionJobStatus']);
Route::post('/polish', [TranscriptionController::class, 'polish']);
Route::get('/license/status', [TranscriptionController::class, 'licenseStatus']);
Route::get('/transcribe/update/zipfile', [TranscriptionController::class, 'downloadUpdate']);

// Webhook routes (public - for Paymongo callbacks)
Route::post('/credits/webhook', [App\Http\Controllers\Api\PaymentController::class, 'webhook']);
