<?php

use App\Http\Controllers\UserManagerController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
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
