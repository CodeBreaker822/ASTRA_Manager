<?php

use App\Http\Controllers\Api\APIController\ApiDashboardController;
use App\Http\Controllers\Api\APIController\ApiTokenController;
use App\Http\Controllers\Api\APIController\TranscriberPackageController;
use App\Http\Controllers\Api\APIController\TranscriptionProviderController;
use App\Http\Controllers\Api\TranscriptionController\TranscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/runpod/audio/{file}', [TranscriptionController::class, 'temporaryRunPodAudio'])
    ->middleware('signed')
    ->where('file', '[^/]+')
    ->name('runpod.audio.temporary');

Route::middleware(['auth', 'can:API-manage_api'])->group(function () {
    Route::redirect('/settings/api', '/dashboard/api');
    Route::get('/dashboard/api', [ApiDashboardController::class, 'index'])->name('api.manager');
    Route::get('/dashboard/api/transcription-providers/health', [TranscriptionProviderController::class, 'transcriptionProviderHealth'])->name('api.transcription-providers.health');
    Route::get('/dashboard/api/transcription-providers/logs', [TranscriptionProviderController::class, 'transcriptionProviderLogs'])->name('api.transcription-providers.logs');
    Route::post('/dashboard/api/transcription-providers/models', [TranscriptionProviderController::class, 'transcriptionProviderModels'])->name('api.transcription-providers.models');
    Route::post('/dashboard/api/transcription-providers', [TranscriptionProviderController::class, 'updateTranscriptionProviders'])->name('api.transcription-providers.update');
    Route::post('/dashboard/api/transcription-providers/order', [TranscriptionProviderController::class, 'reorderTranscriptionProviders'])->name('api.transcription-providers.order');
    Route::post('/dashboard/api/transcriber-package', [TranscriberPackageController::class, 'uploadTranscriberPackage'])->name('api.transcriber-package.upload');
    Route::post('/dashboard/api/transcriber-package/chunk', [TranscriberPackageController::class, 'uploadTranscriberPackageChunk'])->name('api.transcriber-package.chunk');
    Route::post('/dashboard/api/transcriber-package/complete', [TranscriberPackageController::class, 'completeTranscriberPackageUpload'])->name('api.transcriber-package.complete');
    Route::post('/dashboard/api/license-key', [ApiTokenController::class, 'generateLicenseKey'])->name('api.generate-license-key');
    Route::put('/api/settings/update-status/{api}', [ApiTokenController::class, 'updateStatus'])->name('api.update-status');
    Route::put('/api/settings/update-method/{api}', [ApiTokenController::class, 'updateMethod'])->name('api.update-method');
    Route::post('/api/settings/store', [ApiTokenController::class, 'store'])->name('api.store');
    Route::delete('/api/settings/{api}', [ApiTokenController::class, 'destroy'])->name('api.destroy');
});
