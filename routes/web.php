<?php

use Illuminate\Support\Facades\Route;

// The dashboard itself lives in the Report module.
Route::redirect('/', '/dashboard')->name('home');

require __DIR__.'/settings.php';
