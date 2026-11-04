<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\IAM\Http\Api\V1\Controllers\AuthController;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:mobile-login')->name('login');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:mobile-refresh')->name('refresh');

    Route::middleware(['auth:sanctum', 'mobile.session', 'throttle:mobile'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
    });
});
