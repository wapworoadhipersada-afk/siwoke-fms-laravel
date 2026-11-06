<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Modules\Asset\Application\Actions\AcceptHandover;
use Modules\Asset\Application\Actions\MoveAssets;
use Modules\Asset\Application\Actions\RequestHandover;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Database\Seeders\AssetCatalogSeeder;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Asset\Tests\Support\AssetFixtures as Fx;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Master\Tests\Support\MasterFixtures;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, AssetCatalogSeeder::class]);
    $this->bri = MasterFixtures::vendor('BRI');
    $this->cimb = MasterFixtures::vendor('CIMB');
    $this->warehouse = Fx::warehouse();
    $this->tech = Fx::technician();
    $this->leader = Fx::approver();
    $this->servicePoint = MasterFixtures::chain()['area'];
    $this->terminal = MasterFixtures::terminalIn($this->bri, $this->servicePoint);
    [$this->asset] = Fx::receive(Fx::edcModel(), $this->bri, $this->warehouse, ['SN-1']);
    Fx::issue($this->warehouse, $this->tech, [$this->asset]);
    $this->asset = $this->asset->fresh();
});

function moveCommand(object $test, MovementType $type, Holder $from, Holder $to, ?Asset $asset = null): MovementCommand
{
    return new MovementCommand($type, [MoveItem::asset($asset ?? $test->asset->fresh())], $from, $to, $test->tech);
}

function installOn(object $test): void
{
    app(MoveAssets::class)->handle(moveCommand($test, MovementType::Install, Holder::user($test->tech), Holder::terminal($test->terminal)));
}

describe('installing at a merchant', function () {
    it('puts the asset on the terminal and records it', function () {
        $movement = app(MoveAssets::class)->handle(new MovementCommand(
            MovementType::Install, [MoveItem::asset($this->asset)], Holder::user($this->tech), Holder::terminal($this->terminal), $this->tech,
            referenceType: 'ticket', referenceId: 77, latitude: '-6.9175000', longitude: '107.6191000',
        ));

        $asset = $this->asset->fresh();

        expect($asset->status)->toBe(AssetStatus::Installed)
            ->and($asset->holder_type)->toBe(HolderType::Terminal)
            ->and($asset->holder_id)->toBe($this->terminal->id)
            ->and($movement->reference_type)->toBe('ticket')
            ->and($movement->reference_id)->toBe(77)
            ->and($movement->latitude)->toBe('-6.9175000')
            ->and($movement->items->sole()->asset_id)->toBe($asset->id);
    });

    it('refuses a terminal that belongs to another vendor', function () {
        $foreign = MasterFixtures::terminalIn($this->cimb, $this->servicePoint);

        app(MoveAssets::class)->handle(moveCommand($this, MovementType::Install, Holder::user($this->tech), Holder::terminal($foreign)));
    })->throws(ValidationException::class, 'milik vendor lain');

    it('refuses an asset the technician does not hold', function () {
        $someoneElse = Fx::technician();

        app(MoveAssets::class)->handle(moveCommand($this, MovementType::Install, Holder::user($someoneElse), Holder::terminal($this->terminal)));
    })->throws(ValidationException::class, 'tidak berada di pemegang asal');

    it('refuses an asset that is still in the warehouse', function () {
        [$inStock] = Fx::receive(Fx::edcModel(), $this->bri, $this->warehouse, ['SN-9']);

        app(MoveAssets::class)->handle(moveCommand($this, MovementType::Install, Holder::warehouse($this->warehouse), Holder::terminal($this->terminal), $inStock));
    })->throws(ValidationException::class, 'tidak dapat berasal');

    it('cannot install the same asset twice', function () {
        installOn($this);

        installOn($this);
    })->throws(ValidationException::class);

    it('takes the asset back off the terminal into the technician hands', function () {
        installOn($this);

        app(MoveAssets::class)->handle(moveCommand($this, MovementType::Uninstall, Holder::terminal($this->terminal), Holder::user($this->tech)));

        $asset = $this->asset->fresh();

        expect($asset->status)->toBe(AssetStatus::WithTechnician)
            ->and($asset->holder_id)->toBe($this->tech->id);
    });

    it('keeps the whole history of an asset that is installed, removed and installed again', function () {
        installOn($this);
        app(MoveAssets::class)->handle(moveCommand($this, MovementType::Uninstall, Holder::terminal($this->terminal), Holder::user($this->tech)));
        installOn($this);

        $types = $this->asset->movementItems()->with('movement')->orderBy('movement_id')->get()->map(fn ($item) => $item->movement->type)->all();

        expect($types)->toBe([
            MovementType::Receive,
            MovementType::IssueToTechnician,
            MovementType::Install,
            MovementType::Uninstall,
            MovementType::Install,
        ]);
    });
});

it('sends an asset to repair and back', function () {
    $handover = app(RequestHandover::class)->handle(moveCommand($this, MovementType::ReturnToWarehouse, Holder::user($this->tech), Holder::warehouse($this->warehouse)));
    app(AcceptHandover::class)->handle($handover, $this->leader);

    app(MoveAssets::class)->handle(new MovementCommand(MovementType::SendToRepair, [MoveItem::asset($this->asset->fresh())], Holder::warehouse($this->warehouse), Holder::external(), $this->leader));

    expect($this->asset->fresh()->status)->toBe(AssetStatus::InRepair)
        ->and($this->asset->fresh()->holder_type)->toBe(HolderType::External)
        ->and($this->asset->fresh()->holder_id)->toBeNull();

    app(MoveAssets::class)->handle(new MovementCommand(MovementType::ReturnFromRepair, [MoveItem::asset($this->asset->fresh())], Holder::external(), Holder::warehouse($this->warehouse), $this->leader));

    expect($this->asset->fresh()->status)->toBe(AssetStatus::InStock)
        ->and($this->asset->fresh()->holder_id)->toBe($this->warehouse->id);
});

it('declares an installed asset lost, after which nothing can move it', function () {
    installOn($this);

    app(MoveAssets::class)->handle(new MovementCommand(MovementType::MarkLost, [MoveItem::asset($this->asset->fresh())], Holder::terminal($this->terminal), Holder::external(), $this->leader, notes: 'Merchant tutup, mesin tidak ditemukan'));

    expect($this->asset->fresh()->status)->toBe(AssetStatus::Lost);

    app(MoveAssets::class)->handle(moveCommand($this, MovementType::Uninstall, Holder::terminal($this->terminal), Holder::user($this->tech)));
})->throws(ValidationException::class);

it('refuses a status change that does not fit where the asset is', function () {
    // Held by a technician, so it cannot go straight to repair from a warehouse.
    app(MoveAssets::class)->handle(new MovementCommand(MovementType::SendToRepair, [MoveItem::asset($this->asset->fresh())], Holder::warehouse($this->warehouse), Holder::external(), $this->leader));
})->throws(ValidationException::class);

it('rolls back everything when any asset in a batch is invalid', function () {
    [$stray] = Fx::receive(Fx::edcModel(), $this->bri, $this->warehouse, ['SN-STRAY']);

    expect(fn () => app(MoveAssets::class)->handle(new MovementCommand(
        MovementType::Install,
        [MoveItem::asset($this->asset->fresh()), MoveItem::asset($stray)],
        Holder::user($this->tech), Holder::terminal($this->terminal), $this->tech,
    )))->toThrow(ValidationException::class)
        ->and($this->asset->fresh()->status)->toBe(AssetStatus::WithTechnician);
});

it('refuses an unknown asset id', function () {
    app(MoveAssets::class)->handle(new MovementCommand(
        MovementType::Install, [new MoveItem(999999, $this->asset->asset_model_id, $this->bri->id)], Holder::user($this->tech), Holder::terminal($this->terminal), $this->tech,
    ));
})->throws(ValidationException::class);

it('refuses a consumable movement type for a serialized asset', function () {
    app(MoveAssets::class)->handle(moveCommand($this, MovementType::Consume, Holder::user($this->tech), Holder::external()));
})->throws(ValidationException::class);

it('bumps the version on every change so concurrent edits are detectable', function () {
    $before = $this->asset->version;

    installOn($this);

    expect($this->asset->fresh()->version)->toBe($before + 1);
});
