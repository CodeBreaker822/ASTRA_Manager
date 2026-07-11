<?php

use App\Http\Controllers\Api\TranscriptionController;
use Illuminate\Support\Facades\Route;

Route::post('/transcribe', [TranscriptionController::class, 'transcribe']);
Route::get('/transcribe/jobs/{job}', [TranscriptionController::class, 'transcriptionJobStatus']);
Route::post('/polish', [TranscriptionController::class, 'polish']);
Route::get('/license/status', [TranscriptionController::class, 'licenseStatus']);
Route::get('/transcribe/update/zipfile', [TranscriptionController::class, 'downloadUpdate']);
