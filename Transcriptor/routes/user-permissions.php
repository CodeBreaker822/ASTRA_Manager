<?php

use App\Http\Controllers\Auth\UserAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])
    ->name('user_manage.')
    ->group(function () {
        Route::middleware('can:user.manage-permissions')->group(function () {
            Route::get('/permissions', [UserAuthController::class, 'permissions'])->name('permissions');
            Route::get('/get-permissions', [UserAuthController::class, 'getPermissions'])->name('permission.get');
            Route::post('/store-permission', [UserAuthController::class, 'storePermission'])->name('permission.store');
            Route::put('/update-permission/{id}', [UserAuthController::class, 'updatePermission'])->name('permission.update');
            Route::delete('/destroy-permission/{id}', [UserAuthController::class, 'destroyPermission'])->name('permission.destroy');
        });
    });

