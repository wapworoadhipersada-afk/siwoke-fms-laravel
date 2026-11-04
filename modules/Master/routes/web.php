<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Master\Http\Web\Controllers\LocationController;
use Modules\Master\Http\Web\Controllers\MerchantController;
use Modules\Master\Http\Web\Controllers\MerchantSiteController;
use Modules\Master\Http\Web\Controllers\TerminalController;
use Modules\Master\Http\Web\Controllers\VendorController;

Route::middleware(['web', 'auth', 'verified'])->prefix('master')->name('master.')->group(function () {
    Route::resource('vendors', VendorController::class)->except('show');
    Route::resource('locations', LocationController::class)->except('show');
    Route::resource('merchants', MerchantController::class);
    Route::resource('merchants.sites', MerchantSiteController::class)->shallow()->only(['create', 'store', 'edit', 'update', 'destroy']);
    Route::resource('terminals', TerminalController::class)->except('show');
});
