<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Ticket\Http\Web\Controllers\BeritaAcaraController;
use Modules\Ticket\Http\Web\Controllers\TicketController;

Route::middleware(['web', 'auth', 'verified'])->prefix('tickets')->name('tickets.')->group(function () {
    Route::get('/', [TicketController::class, 'index'])->name('index');
    Route::get('create', [TicketController::class, 'create'])->name('create');
    Route::post('/', [TicketController::class, 'store'])->name('store');
    Route::get('{ticket}', [TicketController::class, 'show'])->name('show');
    Route::post('{ticket}/assign', [TicketController::class, 'assign'])->name('assign');
    Route::post('{ticket}/unassign', [TicketController::class, 'unassign'])->name('unassign');
    Route::post('{ticket}/cancel', [TicketController::class, 'cancel'])->name('cancel');
    Route::post('{ticket}/reopen', [TicketController::class, 'reopen'])->name('reopen');

    Route::get('{ticket}/berita-acara/{beritaAcara}', [BeritaAcaraController::class, 'download'])->name('berita-acara.download');
    Route::post('{ticket}/visits/{visitNo}/berita-acara', [BeritaAcaraController::class, 'store'])->whereNumber('visitNo')->name('berita-acara.store');
});
