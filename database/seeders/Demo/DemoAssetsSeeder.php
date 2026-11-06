<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Asset\Application\Actions\AcceptHandover;
use Modules\Asset\Application\Actions\CreateAssetModel;
use Modules\Asset\Application\Actions\CreateWarehouse;
use Modules\Asset\Application\Actions\ReceiveAssets;
use Modules\Asset\Application\Actions\RequestHandover;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetCategory;
use Modules\Asset\Domain\Models\Warehouse;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Domain\Models\Vendor;

/**
 * Asset types, warehouses, stock owned by each vendor, and equipment handed to the technicians (through the
 * same offer-and-accept handover the screens use), so technicians start with machines and paper in hand.
 */
final class DemoAssetsSeeder extends Seeder
{
    /** @var array<string, list<array{0: string, 1: string}>> category => [brand, model] */
    private const MODELS = [
        'EDC' => [['Verifone', 'VX520'], ['Ingenico', 'iCT250'], ['PAX', 'D210'], ['Verifone', 'V240m']],
        'SIM' => [['Telkomsel', 'M2M 4G'], ['Indosat', 'Matrix IoT']],
        'SAM' => [['Bank', 'SAM Card Gen2']],
        'THERMAL_PAPER' => [['Generic', 'Roll 57mm']],
        'CHARGER' => [['Generic', 'Adaptor 9V EDC']],
    ];

    /** @var array<string, list<string>> vendor => area names that get a warehouse */
    private const WAREHOUSES = [
        'BRI' => ['Menteng', 'Bekasi Kota', 'Gubeng'],
        'CIMB' => ['Grogol', 'Beji', 'Klojen'],
    ];

    public function run(CreateAssetModel $createModel, CreateWarehouse $createWarehouse, ReceiveAssets $receive, RequestHandover $request, AcceptHandover $accept): void
    {
        DemoSupport::seed();
        mt_srand(4242);

        $actor = User::query()->role(RoleName::SuperAdmin->value)->orderBy('id')->firstOrFail();

        $models = [];

        foreach (self::MODELS as $category => $list) {
            $categoryId = AssetCategory::query()->where('code', $category)->firstOrFail()->id;

            foreach ($list as [$brand, $name]) {
                $models[$category][] = $createModel->handle(['asset_category_id' => $categoryId, 'brand' => $brand, 'model_name' => $name]);
            }
        }

        $stats = ['edc' => 0, 'sim' => 0, 'sam' => 0];

        foreach (Vendor::query()->whereIn('code', ['BRI', 'CIMB'])->get() as $vendor) {
            $warehouses = $this->warehouses($createWarehouse, $vendor);
            $tag = strtoupper($vendor->code);

            foreach ($warehouses as $index => $warehouse) {
                foreach ($models['EDC'] as $model) {
                    $serials = $this->serials("{$tag}-{$model->model_name}-W{$index}", 6);
                    $receive->handle($model, $vendor, $warehouse, $actor, $serials, 0, 'Penerimaan awal dari vendor');
                    $stats['edc'] += count($serials);
                }

                foreach ($models['SIM'] as $model) {
                    $receive->handle($model, $vendor, $warehouse, $actor, $this->serials("{$tag}-SIM{$model->id}-W{$index}", 12));
                    $stats['sim'] += 12;
                }

                $receive->handle($models['SAM'][0], $vendor, $warehouse, $actor, $this->serials("{$tag}-SAM-W{$index}", 15));
                $stats['sam'] += 15;
                $receive->handle($models['THERMAL_PAPER'][0], $vendor, $warehouse, $actor, [], 600);
                $receive->handle($models['CHARGER'][0], $vendor, $warehouse, $actor, [], 80);
            }

            // Every technician of this vendor leaves a warehouse with three machines and a stock of paper.
            $technicians = User::query()->role(RoleName::Technician->value)->whereIn('id', function ($query) use ($vendor): void {
                $query->select('user_id')->from('user_vendors')->where('vendor_id', $vendor->id);
            })->orderBy('id')->get();

            foreach ($technicians as $position => $technician) {
                $warehouse = $warehouses[$position % count($warehouses)];
                $machines = Asset::query()->where('owner_vendor_id', $vendor->id)->where('status', 'in_stock')
                    ->where('holder_type', 'warehouse')->where('holder_id', $warehouse->id)
                    ->whereIn('asset_model_id', collect($models['EDC'])->pluck('id'))->orderBy('id')->limit(3)->get();

                $items = $machines->map(fn (Asset $asset): MoveItem => MoveItem::asset($asset))->all();
                $items[] = MoveItem::stock($models['THERMAL_PAPER'][0], $vendor, 40);

                $handover = $request->handle(new MovementCommand(
                    type: MovementType::IssueToTechnician,
                    items: $items,
                    from: Holder::warehouse($warehouse),
                    to: Holder::user($technician),
                    actor: $actor,
                    notes: 'Pembekalan awal teknisi',
                ));
                $accept->handle($handover, $technician);
            }
        }

        $this->command?->info("{$stats['edc']} EDC, {$stats['sim']} SIM, {$stats['sam']} SAM di 6 gudang; teknisi sudah membawa mesin dan kertas.");
    }

    /**
     * @return list<Warehouse>
     */
    private function warehouses(CreateWarehouse $create, Vendor $vendor): array
    {
        $result = [];

        foreach (self::WAREHOUSES[$vendor->code] as $index => $areaName) {
            $area = Location::query()->where('type', 'area')->where('name', $areaName)->firstOrFail();
            $result[] = $create->handle(['location_id' => $area->id, 'code' => "WH-{$vendor->code}-".($index + 1), 'name' => "Gudang {$vendor->code} {$areaName}"]);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function serials(string $prefix, int $count): array
    {
        return array_map(fn (int $number): string => sprintf('%s-%s', $prefix, str_pad((string) $number, 4, '0', STR_PAD_LEFT).DemoSupport::digits(3)), range(1, $count));
    }
}
