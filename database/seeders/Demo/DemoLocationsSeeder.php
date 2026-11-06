<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Modules\Master\Application\Actions\CreateLocation;
use Modules\Master\Application\Actions\CreateVendor;
use Modules\Master\Domain\Enums\LocationType;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Domain\Models\Vendor;

/**
 * The two vendors and the three-level location tree they work in: Provinsi → Service Point (one vendor each) →
 * Area Kelolaan. Goes through the same actions the screens use.
 */
final class DemoLocationsSeeder extends Seeder
{
    /**
     * province code => [name, [service point code => [vendor, name, [areas]]]]
     *
     * @var array<string, array{0: string, 1: array<string, array{0: string, 1: string, 2: list<string>}>}>
     */
    private const TREE = [
        'JKT' => ['DKI Jakarta', [
            'SP-JKT-PST' => ['BRI', 'SP Jakarta Pusat', ['Menteng', 'Tanah Abang', 'Gambir']],
            'SP-JKT-SEL' => ['BRI', 'SP Jakarta Selatan', ['Kebayoran Baru', 'Tebet', 'Pasar Minggu']],
            'SP-JKT-BRT' => ['CIMB', 'SP Jakarta Barat', ['Grogol', 'Kebon Jeruk', 'Cengkareng']],
            'SP-JKT-UTR' => ['CIMB', 'SP Jakarta Utara', ['Kelapa Gading', 'Tanjung Priok', 'Penjaringan']],
        ]],
        'JBR' => ['Jawa Barat', [
            'SP-JBR-BKS' => ['BRI', 'SP Bekasi', ['Bekasi Kota', 'Bekasi Timur', 'Cibitung']],
            'SP-JBR-BDG' => ['BRI', 'SP Bandung', ['Coblong', 'Cicendo', 'Buah Batu']],
            'SP-JBR-DPK' => ['CIMB', 'SP Depok', ['Beji', 'Sawangan', 'Cimanggis']],
            'SP-JBR-BGR' => ['CIMB', 'SP Bogor', ['Bogor Tengah', 'Tajur', 'Cibinong']],
        ]],
        'BTN' => ['Banten', [
            'SP-BTN-TNG' => ['BRI', 'SP Tangerang', ['Karawaci', 'Cipondoh', 'Ciledug']],
            'SP-BTN-TSL' => ['CIMB', 'SP Tangerang Selatan', ['Serpong', 'Pamulang', 'Ciputat']],
        ]],
        'JTG' => ['Jawa Tengah', [
            'SP-JTG-SMG' => ['BRI', 'SP Semarang', ['Simpang Lima', 'Banyumanik', 'Tembalang']],
            'SP-JTG-SLO' => ['CIMB', 'SP Solo', ['Laweyan', 'Banjarsari', 'Jebres']],
        ]],
        'JTM' => ['Jawa Timur', [
            'SP-JTM-SBY' => ['BRI', 'SP Surabaya', ['Gubeng', 'Rungkut', 'Wonokromo']],
            'SP-JTM-MLG' => ['CIMB', 'SP Malang', ['Lowokwaru', 'Klojen', 'Blimbing']],
        ]],
    ];

    public function run(CreateVendor $vendors, CreateLocation $locations): void
    {
        $vendor = [
            'BRI' => $vendors->handle(['code' => 'BRI', 'name' => 'Bank Rakyat Indonesia']),
            'CIMB' => $vendors->handle(['code' => 'CIMB', 'name' => 'CIMB Niaga']),
        ];

        foreach (self::TREE as $provinceCode => [$provinceName, $servicePoints]) {
            $province = $locations->handle(['type' => LocationType::Province, 'code' => $provinceCode, 'name' => $provinceName]);

            foreach ($servicePoints as $spCode => [$vendorCode, $spName, $areas]) {
                /** @var Vendor $owner */
                $owner = $vendor[$vendorCode];
                $servicePoint = $locations->handle(['type' => LocationType::ServicePoint, 'parent_id' => $province->id, 'vendor_id' => $owner->id, 'code' => $spCode, 'name' => $spName]);

                foreach ($areas as $index => $areaName) {
                    $locations->handle(['type' => LocationType::Area, 'parent_id' => $servicePoint->id, 'code' => "{$spCode}-".($index + 1), 'name' => $areaName]);
                }
            }
        }

        $this->command?->info(Location::query()->where('type', LocationType::Area->value)->count().' area di '.count(self::TREE).' provinsi untuk BRI dan CIMB.');
    }
}
