<?php

declare(strict_types=1);

namespace Modules\Asset;

use Illuminate\Support\ServiceProvider;
use Modules\Asset\Console\ReconcileAssetsCommand;

final class AssetServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileAssetsCommand::class]);
        }
    }
}
