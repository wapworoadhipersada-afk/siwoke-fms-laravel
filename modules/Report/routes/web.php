<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Report\Http\Web\Controllers\DashboardController;
use Modules\Report\Http\Web\Controllers\TicketReportController;

Route::middleware(['web', 'auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('reports/tickets', [TicketReportController::class, 'index'])->name('reports.tickets');
    Route::get('reports/tickets/export', [TicketReportController::class, 'export'])->middleware('throttle:6,1')->name('reports.tickets.export');
});
