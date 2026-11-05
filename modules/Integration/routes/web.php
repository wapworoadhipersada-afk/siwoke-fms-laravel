<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Integration\Http\Web\Controllers\IntegrationClientController;
use Modules\Integration\Http\Web\Controllers\IntegrationLogController;

Route::middleware(['web', 'auth', 'verified'])->prefix('integration')->name('integration.')->group(function () {
    Route::get('clients', [IntegrationClientController::class, 'index'])->name('clients.index');
    Route::get('clients/create', [IntegrationClientController::class, 'create'])->name('clients.create');
    Route::post('clients', [IntegrationClientController::class, 'store'])->name('clients.store');
    Route::post('clients/{client}/rotate', [IntegrationClientController::class, 'rotate'])->name('clients.rotate');
    Route::post('clients/{client}/toggle', [IntegrationClientController::class, 'toggle'])->name('clients.toggle');

    Route::get('logs', [IntegrationLogController::class, 'index'])->name('logs.index');
    Route::post('outbound/{event}/retry', [IntegrationLogController::class, 'retry'])->name('outbound.retry');
});
