<?php

declare(strict_types=1);

namespace Modules\IAM\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\IAM\Application\Services\SyncNavigationCatalog;

/**
 * Adds sidebar entries that are missing. Entries that exist are never changed, so what an administrator
 * arranged survives every deploy.
 */
class NavigationSeeder extends Seeder
{
    public function run(): void
    {
        app(SyncNavigationCatalog::class)->handle();
    }
}
