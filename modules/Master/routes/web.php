<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Master\Http\Web\Controllers\LocationController;
use Modules\Master\Http\Web\Controllers\MeController;
use Modules\Master\Http\Web\Controllers\MerchantController;
use Modules\Master\Http\Web\Controllers\MerchantSiteController;
use Modules\Master\Http\Web\Controllers\TerminalController;
use Modules\Master\Http\Web\Controllers\UserScopeController;
use Modules\Master\Http\Web\Controllers\VendorController;
use Modules\Master\Http\Web\Controllers\VendorFocusController;

Route::middleware(['web', 'auth', 'verified'])->get('me', [MeController::class, 'show'])->name('me.show');

Route::middleware(['web', 'auth', 'verified'])->put('focus/vendor', [VendorFocusController::class, 'update'])->name('focus.vendor');

Route::middleware(['web', 'auth', 'verified'])->prefix('master')->name('master.')->group(function () {
    Route::resource('vendors', VendorController::class)->except('show');
    Route::resource('locations', LocationController::class)->except('show');
    Route::resource('merchants', MerchantController::class);
    Route::resource('merchants.sites', MerchantSiteController::class)->shallow()->only(['create', 'store', 'edit', 'update', 'destroy']);
    Route::resource('terminals', TerminalController::class)->except('show');
    Route::get('user-scopes/{managedUser}', [UserScopeController::class, 'edit'])->name('user-scopes.edit');
    Route::put('user-scopes/{managedUser}', [UserScopeController::class, 'update'])->name('user-scopes.update');
});
