<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Housekeeping and integrity checks (docs/operasional.md). The vendor callback retry is scheduled by its own module.
Schedule::command('asset:reconcile')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
Schedule::command('document:verify')->weeklyOn(0, '03:00')->withoutOverlapping()->onOneServer();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('activitylog:clean --force')->daily();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
