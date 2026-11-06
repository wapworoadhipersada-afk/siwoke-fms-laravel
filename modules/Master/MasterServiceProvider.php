<?php

declare(strict_types=1);

namespace Modules\Master;

use Illuminate\Support\ServiceProvider;
use Modules\Master\Application\Services\LocationAccess;
use Modules\Master\Application\Services\VendorAccess;

final class MasterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LocationAccess::class);
        // One instance per request, so the vendors a person may use are read once and can be refreshed after a change.
        $this->app->singleton(VendorAccess::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
    }
}
