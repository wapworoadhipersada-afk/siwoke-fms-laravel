<?php

declare(strict_types=1);

namespace Modules\Asset;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Asset\Console\ReconcileAssetsCommand;

final class AssetServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        Route::middleware('api')
            ->prefix('api/mobile/v1')
            ->name('api.mobile.v1.')
            ->group(__DIR__.'/routes/api_v1.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileAssetsCommand::class]);
        }
    }
}
