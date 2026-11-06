<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Master\Http\Web\Controllers\LocationController;
use Modules\Master\Http\Web\Controllers\MeController;
use Modules\Master\Http\Web\Controllers\MerchantController;
use Modules\Master\Http\Web\Controllers\MerchantDocumentController;
use Modules\Master\Http\Web\Controllers\MerchantSiteController;
use Modules\Master\Http\Web\Controllers\TeamController;
use Modules\Master\Http\Web\Controllers\TerminalController;
use Modules\Master\Http\Web\Controllers\UserScopeController;
use Modules\Master\Http\Web\Controllers\VendorController;
use Modules\Master\Http\Web\Controllers\VendorFocusController;

Route::middleware(['web', 'auth', 'verified'])->get('me', [MeController::class, 'show'])->name('me.show');

Route::middleware(['web', 'auth', 'verified'])->put('focus/vendor', [VendorFocusController::class, 'update'])->name('focus.vendor');

Route::middleware(['web', 'auth', 'verified'])->prefix('master')->name('master.')->group(function () {
    Route::get('documents/template', [MerchantDocumentController::class, 'template'])->name('documents.template');
    Route::get('documents', [MerchantDocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/create', [MerchantDocumentController::class, 'create'])->name('documents.create');
    Route::post('documents', [MerchantDocumentController::class, 'store'])->middleware('throttle:30,1')->name('documents.store');
    Route::get('documents/{document}', [MerchantDocumentController::class, 'show'])->name('documents.show');
    Route::post('documents/{document}/import', [MerchantDocumentController::class, 'import'])->middleware('throttle:20,1')->name('documents.import');
    Route::post('documents/{document}/recheck', [MerchantDocumentController::class, 'recheck'])->name('documents.recheck');
    Route::delete('documents/{document}', [MerchantDocumentController::class, 'destroy'])->name('documents.destroy');
    Route::get('team', [TeamController::class, 'index'])->name('team.index');
    Route::resource('vendors', VendorController::class)->except('show');
    Route::get('provinces', [LocationController::class, 'provinces'])->name('provinces.index');
    Route::get('service-points', [LocationController::class, 'servicePoints'])->name('service-points.index');
    Route::get('areas', [LocationController::class, 'areas'])->name('areas.index');
    Route::resource('locations', LocationController::class)->except('show');
    Route::resource('merchants', MerchantController::class);
    Route::resource('merchants.sites', MerchantSiteController::class)->shallow()->only(['create', 'store', 'edit', 'update', 'destroy']);
    Route::resource('terminals', TerminalController::class)->except('show');
    Route::get('user-scopes/{managedUser}', [UserScopeController::class, 'edit'])->name('user-scopes.edit');
    Route::put('user-scopes/{managedUser}', [UserScopeController::class, 'update'])->name('user-scopes.update');
});
