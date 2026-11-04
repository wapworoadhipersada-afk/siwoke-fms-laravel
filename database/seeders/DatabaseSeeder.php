<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Asset\Database\Seeders\AssetCatalogSeeder;
use Modules\IAM\Database\Seeders\DevUserSeeder;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Ticket\Database\Seeders\TicketCatalogSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(AssetCatalogSeeder::class);
        $this->call(TicketCatalogSeeder::class);
        $this->call(DevUserSeeder::class);
    }
}
