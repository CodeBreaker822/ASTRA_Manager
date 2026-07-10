<?php

use App\Http\Controllers\Api\APIController;
use App\Http\Controllers\Api\TranscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/transcriber/{zipfile}', [TranscriptionController::class, 'downloadUpdate'])
    ->where('zipfile', '[^/]+')
    ->name('transcriber.update.download');

Route::get('/runpod/audio/{file}', [TranscriptionController::class, 'temporaryRunPodAudio'])
    ->middleware('signed')
    ->where('file', '[^/]+')
    ->name('runpod.audio.temporary');

Route::middleware(['auth', 'can:API-manage_api'])->group(function () {
    Route::get('/settings/api', [APIController::class, 'index'])->name('api.manager');
    Route::get('/settings/api/transcription-providers/health', [APIController::class, 'transcriptionProviderHealth'])->name('api.transcription-providers.health');
    Route::get('/settings/api/transcription-providers/logs', [APIController::class, 'transcriptionProviderLogs'])->name('api.transcription-providers.logs');
    Route::post('/settings/api/transcription-providers', [APIController::class, 'updateTranscriptionProviders'])->name('api.transcription-providers.update');
    Route::post('/settings/api/transcription-providers/order', [APIController::class, 'reorderTranscriptionProviders'])->name('api.transcription-providers.order');
    Route::post('/settings/api/transcriber-package', [APIController::class, 'uploadTranscriberPackage'])->name('api.transcriber-package.upload');
    Route::post('/settings/api/license-key', [APIController::class, 'generateLicenseKey'])->name('api.generate-license-key');
    Route::put('/api/settings/update-status/{id}', [APIController::class, 'updateStatus'])->name('api.update-status');
    Route::put('/api/settings/update-method/{id}', [APIController::class, 'updateMethod'])->name('api.update-method');
    Route::post('/api/settings/store', [APIController::class, 'store'])->name('api.store');
    Route::delete('/api/settings/{aPI}', [APIController::class, 'destroy'])->name('api.destroy');
});
