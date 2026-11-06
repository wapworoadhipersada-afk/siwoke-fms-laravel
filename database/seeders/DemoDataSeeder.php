<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Demo\DemoAssetsSeeder;
use Database\Seeders\Demo\DemoLocationsSeeder;
use Database\Seeders\Demo\DemoMerchantsSeeder;
use Database\Seeders\Demo\DemoPeopleSeeder;
use Database\Seeders\Demo\DemoTicketsSeeder;
use Illuminate\Database\Seeder;
use Modules\Master\Domain\Models\Vendor;

/**
 * A busy, believable installation for looking around: two vendors (BRI and CIMB), a location tree, field teams,
 * merchants and TIDs from documents, assets and stock, and a few months of tickets in every state.
 *
 * Local and testing only, and only on a database that does not already hold these vendors. Run it after the
 * normal seeders:  php artisan migrate:fresh --seed && php artisan db:seed --class=DemoDataSeeder
 */
final class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('DemoDataSeeder only runs in the local or testing environment; skipped.');

            return;
        }

        if (Vendor::query()->whereIn('code', ['BRI', 'CIMB'])->exists()) {
            $this->command?->warn('Vendor BRI atau CIMB sudah ada; data demo tidak ditambahkan lagi. Jalankan migrate:fresh --seed dulu bila ingin mengulang.');

            return;
        }

        $this->call([
            DemoLocationsSeeder::class,
            DemoPeopleSeeder::class,
            DemoMerchantsSeeder::class,
            DemoAssetsSeeder::class,
            DemoTicketsSeeder::class,
        ]);
    }
}
