<?php

declare(strict_types=1);

namespace Modules\Report;

use Illuminate\Support\ServiceProvider;
use Modules\Report\Console\HealthCheckCommand;

final class ReportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([HealthCheckCommand::class]);
        }
    }
}
