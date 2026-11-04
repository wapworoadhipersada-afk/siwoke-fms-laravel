<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Modules\Asset\Application\Actions\ReceiveAssets;
use Modules\Asset\Database\Seeders\AssetCatalogSeeder;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetStock;
use Modules\Asset\Tests\Support\AssetFixtures as Fx;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Master\Tests\Support\MasterFixtures;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, AssetCatalogSeeder::class]);
    $this->vendor = MasterFixtures::vendor('BRI');
    $this->warehouse = Fx::warehouse();
    $this->actor = Fx::approver();
    $this->edc = Fx::edcModel();
});

function receiveSerials(object $test, array $serials, ?object $model = null): void
{
    app(ReceiveAssets::class)->handle($model ?? $test->edc, $test->vendor, $test->warehouse, $test->actor, $serials);
}

describe('serialized assets', function () {
    it('registers each serial in the warehouse under the vendor, with one ledger movement', function () {
        $movement = app(ReceiveAssets::class)->handle($this->edc, $this->vendor, $this->warehouse, $this->actor, [' SN-001 ', 'SN-002'], notes: 'Kiriman BRI batch 1');

        $assets = Asset::query()->orderBy('id')->get();

        expect($assets)->toHaveCount(2)
            ->and($assets->pluck('serial_number')->all())->toBe(['SN-001', 'SN-002'])
            ->and($assets->every(fn (Asset $a) => $a->status === AssetStatus::InStock
                && $a->holder_type === HolderType::Warehouse
                && $a->holder_id === $this->warehouse->id
                && $a->owner_vendor_id === $this->vendor->id
                && $a->last_movement_id === $movement->id
                && $a->version === 1))->toBeTrue()
            ->and($movement->type)->toBe(MovementType::Receive)
            ->and($movement->from_holder_type)->toBe(HolderType::External)
            ->and($movement->to_holder_id)->toBe($this->warehouse->id)
            ->and($movement->actor_id)->toBe($this->actor->id)
            ->and($movement->notes)->toBe('Kiriman BRI batch 1')
            ->and($movement->items)->toHaveCount(2)
            ->and($movement->movement_no)->toMatch('/^MV-\d{8}-\d{6}$/');
    });

    it('numbers movements uniquely and in order', function () {
        $first = app(ReceiveAssets::class)->handle($this->edc, $this->vendor, $this->warehouse, $this->actor, ['A1']);
        $second = app(ReceiveAssets::class)->handle($this->edc, $this->vendor, $this->warehouse, $this->actor, ['A2']);

        expect($second->movement_no)->not->toBe($first->movement_no)
            ->and($second->id)->toBeGreaterThan($first->id);
    });

    it('rejects an empty list and blank lines', function () {
        receiveSerials($this, ['  ', '']);
    })->throws(ValidationException::class, 'Isi minimal satu nomor seri');

    it('rejects a serial repeated within the list, ignoring case', function () {
        receiveSerials($this, ['SN-1', 'sn-1']);
    })->throws(ValidationException::class, 'ganda');

    it('rejects a serial already registered for that model', function () {
        receiveSerials($this, ['SN-1']);
        receiveSerials($this, ['sn-1']);
    })->throws(ValidationException::class, 'sudah terdaftar');

    it('registers nothing when any serial in the batch is a duplicate', function () {
        receiveSerials($this, ['SN-1']);

        expect(fn () => receiveSerials($this, ['SN-2', 'SN-1']))->toThrow(ValidationException::class)
            ->and(Asset::query()->count())->toBe(1);
    });

    it('refuses inactive models, vendors and warehouses', function (string $what) {
        match ($what) {
            'model' => $this->edc->update(['is_active' => false]),
            'vendor' => $this->vendor->update(['is_active' => false]),
            'warehouse' => $this->warehouse->update(['is_active' => false]),
        };

        receiveSerials($this, ['SN-1']);
    })->with(['model', 'vendor', 'warehouse'])->throws(ValidationException::class);

    it('refuses more than 500 units at once', function () {
        receiveSerials($this, array_map(fn (int $n) => "SN-{$n}", range(1, 501)));
    })->throws(ValidationException::class, 'Maksimal');
});

describe('consumables', function () {
    it('adds to the warehouse balance and records the quantity', function () {
        $paper = Fx::paperModel();

        $movement = Fx::receiveStock($paper, $this->vendor, $this->warehouse, 100, $this->actor);
        Fx::receiveStock($paper, $this->vendor, $this->warehouse, 50, $this->actor);

        $stock = AssetStock::query()->sole();

        expect($stock->quantity)->toBe(150)
            ->and($stock->owner_vendor_id)->toBe($this->vendor->id)
            ->and($movement->items->sole()->quantity)->toBe(100)
            ->and($movement->items->sole()->asset_id)->toBeNull()
            ->and(Asset::query()->count())->toBe(0);
    });

    it('keeps a separate balance per vendor', function () {
        $paper = Fx::paperModel();

        Fx::receiveStock($paper, $this->vendor, $this->warehouse, 10, $this->actor);
        Fx::receiveStock($paper, MasterFixtures::vendor('CIMB'), $this->warehouse, 7, $this->actor);

        expect(AssetStock::query()->count())->toBe(2)
            ->and(AssetStock::query()->sum('quantity'))->toBe(17);
    });

    it('rejects a non-positive quantity', function (int $quantity) {
        Fx::receiveStock(Fx::paperModel(), $this->vendor, $this->warehouse, $quantity, $this->actor);
    })->with([0, -5])->throws(ValidationException::class, 'Jumlah');
});
