<?php

use App\Http\Controllers\UserManagerController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('admin/users', [UserManagerController::class, 'index'])->name('admin.users.index');
    Route::post('admin/users', [UserManagerController::class, 'store'])->name('admin.users.store');
    Route::put('admin/users/{user}', [UserManagerController::class, 'update'])->name('admin.users.update');
    Route::delete('admin/users/{user}', [UserManagerController::class, 'destroy'])->name('admin.users.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/transcription-web.php';
require __DIR__.'/chatbot.php';
