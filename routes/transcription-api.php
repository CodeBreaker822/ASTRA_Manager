<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TranscriptionController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

Route::post('/transcribe', [TranscriptionController::class, 'transcribe']);
Route::get('/transcribe/jobs/{job}', [TranscriptionController::class, 'transcriptionJobStatus']);
Route::post('/polish', [TranscriptionController::class, 'polish']);
Route::get('/license/status', [TranscriptionController::class, 'licenseStatus']);
Route::get('/transcribe/update/zipfile', [TranscriptionController::class, 'downloadUpdate']);
