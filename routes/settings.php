<?php

use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('user-password.update');

    Route::view('settings/appearance', 'settings.appearance')->name('appearance.edit');

    Route::view('settings/recording', 'settings.recording')->name('recording.edit');

    Route::get('settings/billing', [BillingController::class, 'edit'])->name('billing.edit');
    Route::post('settings/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::get('settings/billing/success', [BillingController::class, 'success'])->name('billing.success');
    Route::get('settings/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
});
