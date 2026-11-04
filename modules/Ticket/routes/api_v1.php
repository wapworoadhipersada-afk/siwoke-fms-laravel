<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Ticket\Http\Api\V1\Controllers\TicketController;

Route::middleware(['auth:sanctum', 'mobile.session', 'throttle:mobile'])->group(function () {
    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::get('reason-codes', [TicketController::class, 'reasons'])->name('reason-codes');

    Route::post('tickets/{ticket}/accept', [TicketController::class, 'accept'])->name('tickets.accept');
    Route::post('tickets/{ticket}/reject', [TicketController::class, 'reject'])->name('tickets.reject');
    Route::post('tickets/{ticket}/depart', [TicketController::class, 'depart'])->name('tickets.depart');
    Route::post('tickets/{ticket}/arrive', [TicketController::class, 'arrive'])->name('tickets.arrive');
    Route::post('tickets/{ticket}/start', [TicketController::class, 'start'])->name('tickets.start');
    Route::post('tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');
});
