<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\IAM\Database\Seeders\DevUserSeeder;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(DevUserSeeder::class);
    }
}
