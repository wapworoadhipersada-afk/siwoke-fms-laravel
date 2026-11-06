<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Application\Actions\CancelHandover;
use Modules\Asset\Application\Actions\CreateAssetModel;
use Modules\Asset\Application\Actions\CreateWarehouse;
use Modules\Asset\Application\Actions\DeleteAssetModel;
use Modules\Asset\Application\Actions\DeleteWarehouse;
use Modules\Asset\Application\Actions\MoveAssets;
use Modules\Asset\Application\Actions\UpdateAsset;
use Modules\Asset\Application\Actions\UpdateAssetModel;
use Modules\Asset\Application\Actions\UpdateWarehouse;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Database\Seeders\AssetCatalogSeeder;
use Modules\Asset\Domain\Enums\AssetCondition;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Enums\TrackingMode;
use Modules\Asset\Domain\Models\AssetCategory;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Asset\Domain\Models\Warehouse;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Asset\Tests\Support\AssetFixtures as Fx;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Master\Tests\Support\MasterFixtures;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, AssetCatalogSeeder::class]);
    $this->vendor = MasterFixtures::vendor();
});

describe('categories', function () {
    it('seeds the fixed categories with the right tracking mode, and can be re-run', function () {
        $this->seed(AssetCatalogSeeder::class);

        $modes = AssetCategory::query()->pluck('tracking_mode', 'code')->map(fn (TrackingMode $mode) => $mode->value)->all();

        expect($modes)->toBe([
            'EDC' => 'serialized', 'SIM' => 'serialized', 'SAM' => 'serialized',
            'THERMAL_PAPER' => 'quantity', 'CHARGER' => 'quantity', 'PERIPHERAL' => 'quantity',
        ]);
    });
});

describe('models', function () {
    it('does not allow the same brand and model twice in a category, ignoring case', function () {
        $category = AssetCategory::query()->where('code', 'EDC')->firstOrFail();
        app(CreateAssetModel::class)->handle(['asset_category_id' => $category->id, 'brand' => 'PAX', 'model_name' => 'A920']);

        app(CreateAssetModel::class)->handle(['asset_category_id' => $category->id, 'brand' => 'pax', 'model_name' => 'a920']);
    })->throws(ValidationException::class, 'sudah terdaftar');

    it('can be edited but not moved to another category', function () {
        $model = Fx::edcModel();

        app(UpdateAssetModel::class)->handle($model, ['brand' => 'Ingenico', 'asset_category_id' => 999]);

        expect($model->fresh()->brand)->toBe('Ingenico')
            ->and($model->fresh()->asset_category_id)->not->toBe(999);
    });

    it('cannot be deleted once it has units or history', function () {
        $model = Fx::edcModel();
        Fx::receive($model, $this->vendor, Fx::warehouse(), ['SN-1']);

        app(DeleteAssetModel::class)->handle($model);
    })->throws(ValidationException::class, 'riwayat');

    it('can be deleted while unused', function () {
        $model = Fx::edcModel();

        app(DeleteAssetModel::class)->handle($model);

        expect(AssetModel::query()->find($model->id))->toBeNull();
    });
});

describe('warehouses', function () {
    it('normalises and keeps its code unique', function () {
        $location = MasterFixtures::chain()['area'];
        $warehouse = app(CreateWarehouse::class)->handle(['location_id' => $location->id, 'code' => ' gdg-bdg ', 'name' => 'Gudang Bandung']);

        expect($warehouse->code)->toBe('GDG-BDG');

        app(CreateWarehouse::class)->handle(['location_id' => $location->id, 'code' => 'Gdg-Bdg', 'name' => 'Duplikat']);
    })->throws(ValidationException::class, 'Kode gudang');

    it('needs an existing location and an active person in charge', function () {
        expect(fn () => app(CreateWarehouse::class)->handle(['location_id' => 999999, 'code' => 'X1', 'name' => 'X']))
            ->toThrow(ValidationException::class, 'Lokasi');

        $location = MasterFixtures::chain()['area'];
        $inactive = User::factory()->create(['is_active' => false]);

        expect(fn () => app(CreateWarehouse::class)->handle(['location_id' => $location->id, 'code' => 'X2', 'name' => 'X', 'pic_user_id' => $inactive->id]))
            ->toThrow(ValidationException::class, 'PIC');
    });

    it('can be edited', function () {
        $warehouse = Fx::warehouse();

        app(UpdateWarehouse::class)->handle($warehouse, ['name' => 'Gudang Pusat', 'is_active' => false]);

        expect($warehouse->fresh()->name)->toBe('Gudang Pusat')->and($warehouse->fresh()->is_active)->toBeFalse();
    });

    it('cannot be deleted while it holds assets', function () {
        $warehouse = Fx::warehouse();
        Fx::receive(Fx::edcModel(), $this->vendor, $warehouse, ['SN-1']);

        app(DeleteWarehouse::class)->handle($warehouse);
    })->throws(ValidationException::class, 'menyimpan');

    it('cannot be deleted while it holds stock', function () {
        $warehouse = Fx::warehouse();
        Fx::receiveStock(Fx::paperModel(), $this->vendor, $warehouse, 3);

        app(DeleteWarehouse::class)->handle($warehouse);
    })->throws(ValidationException::class, 'menyimpan');

    it('cannot be deleted while a handover is waiting on it', function () {
        $warehouse = Fx::warehouse();
        [$asset] = Fx::receive(Fx::edcModel(), $this->vendor, $warehouse, ['SN-1']);
        $handover = Fx::offer($warehouse, Fx::technician(), [MoveItem::asset($asset)]);
        app(CancelHandover::class)->handle($handover, Fx::approver());
        // Emptied again, but the asset is still physically there.

        app(DeleteWarehouse::class)->handle($warehouse);
    })->throws(ValidationException::class, 'menyimpan');

    it('can be deleted once empty', function () {
        $warehouse = Fx::warehouse();

        app(DeleteWarehouse::class)->handle($warehouse);

        expect(Warehouse::query()->find($warehouse->id))->toBeNull();
    });
});

describe('assets', function () {
    it('lets condition and tag change, and audits that', function () {
        [$asset] = Fx::receive(Fx::edcModel(), $this->vendor, Fx::warehouse(), ['SN-1']);

        app(UpdateAsset::class)->handle($asset, AssetCondition::Damaged, ' TAG-009 ');

        $activity = Activity::query()->where('log_name', 'asset')->where('event', 'updated')->latest('id')->first();

        expect($asset->fresh()->condition)->toBe(AssetCondition::Damaged)
            ->and($asset->fresh()->asset_tag)->toBe('TAG-009')
            ->and($activity)->not->toBeNull();
    });

    it('does not write an audit entry for movements, because the ledger already records them', function () {
        $warehouse = Fx::warehouse();
        [$asset] = Fx::receive(Fx::edcModel(), $this->vendor, $warehouse, ['SN-1']);
        $before = Activity::query()->where('log_name', 'asset')->where('event', 'updated')->count();

        app(MoveAssets::class)->handle(new MovementCommand(
            MovementType::SendToRepair, [MoveItem::asset($asset)], Holder::warehouse($warehouse), Holder::external(), Fx::approver(),
        ));

        expect(Activity::query()->where('log_name', 'asset')->where('event', 'updated')->count())->toBe($before);
    });
});
