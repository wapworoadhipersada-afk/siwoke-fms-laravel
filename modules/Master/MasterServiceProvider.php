<?php

declare(strict_types=1);

namespace Modules\Master;

use Illuminate\Support\ServiceProvider;
use Modules\Master\Application\Services\LocationAccess;

final class MasterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LocationAccess::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
    }
}
