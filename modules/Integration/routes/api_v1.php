<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Integration\Http\Api\V1\Controllers\VendorTicketController;

// Everything here has already passed the signature check (see IntegrationServiceProvider).
Route::get('tickets/{vendorTicketNo}', [VendorTicketController::class, 'show'])->where('vendorTicketNo', '[^/]+')->name('tickets.show');
Route::post('tickets', [VendorTicketController::class, 'store'])->name('tickets.store');
Route::post('tickets/{vendorTicketNo}/cancel', [VendorTicketController::class, 'cancel'])->where('vendorTicketNo', '[^/]+')->name('tickets.cancel');
