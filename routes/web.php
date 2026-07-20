<?php

use App\Http\Controllers\BlogController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\PayMongoWebhookController;
use App\Http\Controllers\UserManagerController;
use App\Http\Controllers\Web\TranscriptActionController;
use App\Http\Controllers\Web\TranscriptionController;
use App\Http\Controllers\Web\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MarketingController::class, 'landing'])->name('home');
Route::get('/features', [MarketingController::class, 'features'])->name('features');
Route::get('/price', [MarketingController::class, 'price'])->name('price');
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');
Route::get('/download', [DownloadController::class, 'index'])->name('download');
Route::get('/download/latest', [DownloadController::class, 'latest'])->name('download.latest');
Route::post('/paymongo/webhook', PayMongoWebhookController::class)->name('paymongo.webhook');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/workspace')->name('dashboard');
    Route::get('workspace', [WorkspaceController::class, 'index'])->name('workspace.index');
    Route::post('workspace', [WorkspaceController::class, 'store'])->name('workspace.store');
    Route::get('workspace/{project}', [WorkspaceController::class, 'show'])->name('workspace.show');
    Route::put('workspace/{project}', [WorkspaceController::class, 'update'])->name('workspace.update');
    Route::delete('workspace/{project}', [WorkspaceController::class, 'destroy'])->name('workspace.destroy');
    Route::get('workspace/{project}/status', [TranscriptionController::class, 'status'])->name('workspace.status');
    Route::post('workspace/{project}/upload', [TranscriptionController::class, 'upload'])->middleware('can.transcribe')->name('workspace.upload');
    Route::post('workspace/{project}/chunk', [TranscriptionController::class, 'chunk'])->middleware('can.transcribe')->name('workspace.chunk');
    Route::post('workspace/{project}/transcripts/{transcript}/cancel', [TranscriptionController::class, 'cancel'])->name('workspace.transcripts.cancel');
    Route::post('workspace/{project}/transcripts/{transcript}/polish', [TranscriptActionController::class, 'polish'])->name('workspace.transcripts.polish');
    Route::post('workspace/{project}/transcripts/{transcript}/summarize', [TranscriptActionController::class, 'summarize'])->name('workspace.transcripts.summarize');
    Route::get('workspace/{project}/transcripts/{transcript}/export', [TranscriptActionController::class, 'export'])->name('workspace.transcripts.export');

    Route::get('settings/users', [UserManagerController::class, 'index'])->name('settings.users.index');
    Route::post('settings/users', [UserManagerController::class, 'store'])->name('settings.users.store');
    Route::put('settings/users/{user}', [UserManagerController::class, 'update'])->name('settings.users.update');
    Route::delete('settings/users/{user}', [UserManagerController::class, 'destroy'])->name('settings.users.destroy');
    Route::post('settings/users/positions', [UserManagerController::class, 'storePosition'])->name('settings.users.positions.store');
    Route::put('settings/users/positions/{position}', [UserManagerController::class, 'updatePosition'])->name('settings.users.positions.update');
    Route::delete('settings/users/positions/{position}', [UserManagerController::class, 'destroyPosition'])->name('settings.users.positions.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/transcription-web.php';
require __DIR__.'/chatbot.php';
