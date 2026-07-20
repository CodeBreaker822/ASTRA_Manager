<?php

use App\Http\Controllers\BlogController;
use App\Http\Controllers\DashboardBlogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardPageController;
use App\Http\Controllers\DashboardPricingController;
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
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::middleware('can:cms.manage-blog')->prefix('dashboard/blog')->name('dashboard.blog.')->group(function (): void {
        Route::get('/', [DashboardBlogController::class, 'index'])->name('index');
        Route::get('create', [DashboardBlogController::class, 'create'])->name('create');
        Route::post('/', [DashboardBlogController::class, 'store'])->name('store');
        Route::post('preview', [DashboardBlogController::class, 'preview'])->name('preview');
        Route::get('{post}/edit', [DashboardBlogController::class, 'edit'])->name('edit');
        Route::put('{post}', [DashboardBlogController::class, 'update'])->name('update');
        Route::delete('{post}', [DashboardBlogController::class, 'destroy'])->name('destroy');
        Route::post('{post}/publish', [DashboardBlogController::class, 'publish'])->name('publish');
    });
    Route::get('dashboard/pricing', [DashboardPricingController::class, 'edit'])->middleware('can:cms.manage-pricing')->name('dashboard.pricing.edit');
    Route::put('dashboard/pricing', [DashboardPricingController::class, 'update'])->middleware('can:cms.manage-pricing')->name('dashboard.pricing.update');
    Route::get('dashboard/pages/features', [DashboardPageController::class, 'features'])->middleware('can:cms.manage-pages')->name('dashboard.pages.features.edit');
    Route::put('dashboard/pages/features', [DashboardPageController::class, 'updateFeatures'])->middleware('can:cms.manage-pages')->name('dashboard.pages.features.update');
    Route::get('dashboard/pages/download', [DashboardPageController::class, 'download'])->middleware('can:cms.manage-pages')->name('dashboard.pages.download.edit');
    Route::put('dashboard/pages/download', [DashboardPageController::class, 'updateDownload'])->middleware('can:cms.manage-pages')->name('dashboard.pages.download.update');

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

    Route::redirect('settings/users', 'dashboard/users');
    Route::prefix('dashboard/users')->name('dashboard.users.')->group(function (): void {
        Route::get('/', [UserManagerController::class, 'index'])->name('index');
        Route::post('/', [UserManagerController::class, 'store'])->name('store');
        Route::put('{user}', [UserManagerController::class, 'update'])->name('update');
        Route::delete('{user}', [UserManagerController::class, 'destroy'])->name('destroy');
        Route::post('positions', [UserManagerController::class, 'storePosition'])->name('positions.store');
        Route::put('positions/{position}', [UserManagerController::class, 'updatePosition'])->name('positions.update');
        Route::delete('positions/{position}', [UserManagerController::class, 'destroyPosition'])->name('positions.destroy');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/transcription-web.php';
require __DIR__.'/chatbot.php';
