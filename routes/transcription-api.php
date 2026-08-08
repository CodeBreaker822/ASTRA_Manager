<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TranscriptionController\LicenseController;
use App\Http\Controllers\Api\TranscriptionController\PolishController;
use App\Http\Controllers\Api\TranscriptionController\TranscriptionController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:desktop-login');
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

Route::post('/transcribe', [TranscriptionController::class, 'transcribe']);
Route::get('/transcribe/jobs/{job}', [TranscriptionController::class, 'transcriptionJobStatus']);
Route::post('/polish', [PolishController::class, 'polish']);
Route::get('/license/status', [LicenseController::class, 'licenseStatus']);
