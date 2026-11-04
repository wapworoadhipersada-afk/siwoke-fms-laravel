<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Asset\Http\Web\Controllers\AssetController;
use Modules\Asset\Http\Web\Controllers\AssetModelController;
use Modules\Asset\Http\Web\Controllers\HandoverController;
use Modules\Asset\Http\Web\Controllers\StockController;
use Modules\Asset\Http\Web\Controllers\WarehouseController;

Route::middleware(['web', 'auth', 'verified'])->prefix('asset')->name('asset.')->group(function () {
    Route::resource('models', AssetModelController::class)->except('show');
    Route::resource('warehouses', WarehouseController::class)->except('show');

    Route::resource('assets', AssetController::class)->only(['index', 'create', 'store', 'show', 'update']);
    Route::post('assets/{asset}/move', [AssetController::class, 'move'])->name('assets.move');

    Route::get('stock', [StockController::class, 'index'])->name('stock.index');

    Route::get('handovers', [HandoverController::class, 'index'])->name('handovers.index');
    Route::get('handovers/create', [HandoverController::class, 'create'])->name('handovers.create');
    Route::post('handovers', [HandoverController::class, 'store'])->name('handovers.store');
    Route::post('handovers/{handover}/accept', [HandoverController::class, 'accept'])->name('handovers.accept');
    Route::post('handovers/{handover}/reject', [HandoverController::class, 'reject'])->name('handovers.reject');
    Route::post('handovers/{handover}/cancel', [HandoverController::class, 'cancel'])->name('handovers.cancel');
});
