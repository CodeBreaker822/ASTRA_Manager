<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AIBot\AIController;

// Chatbot API routes
Route::prefix('api/chatbot')->middleware(['web', 'auth'])->group(function () {
    Route::post('/send', [AIController::class, 'sendMessage'])->name('chatbot.send');
    Route::post('/clear', [AIController::class, 'clearChat'])->name('chatbot.clear');
    Route::get('/history', [AIController::class, 'getHistory'])->name('chatbot.history');
    Route::get('/session/info', [AIController::class, 'getSessionInfo'])->name('chatbot.session.info');
});
