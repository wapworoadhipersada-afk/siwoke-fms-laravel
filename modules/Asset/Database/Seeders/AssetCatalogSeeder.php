<?php

declare(strict_types=1);

namespace Modules\Asset\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Asset\Domain\Enums\TrackingMode;
use Modules\Asset\Domain\Models\AssetCategory;

/**
 * The fixed asset categories. Safe to run on every deploy.
 */
class AssetCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['EDC', 'Mesin EDC', TrackingMode::Serialized],
            ['SIM', 'SIM Card', TrackingMode::Serialized],
            ['SAM', 'SAM Card', TrackingMode::Serialized],
            ['THERMAL_PAPER', 'Thermal Paper', TrackingMode::Quantity],
            ['CHARGER', 'Charger', TrackingMode::Quantity],
            ['PERIPHERAL', 'Peripheral lainnya', TrackingMode::Quantity],
        ];

        foreach ($categories as [$code, $name, $mode]) {
            AssetCategory::query()->updateOrCreate(['code' => $code], ['name' => $name, 'tracking_mode' => $mode, 'is_active' => true]);
        }
    }
}
