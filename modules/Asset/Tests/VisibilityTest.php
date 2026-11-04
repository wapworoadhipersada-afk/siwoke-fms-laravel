<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Asset\Application\Actions\AcceptHandover;
use Modules\Asset\Application\Actions\MoveAssets;
use Modules\Asset\Application\Actions\RequestHandover;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Database\Seeders\AssetCatalogSeeder;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetHandover;
use Modules\Asset\Domain\Models\AssetStock;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Asset\Tests\Support\AssetFixtures as Fx;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Master\Application\Actions\SyncUserLocationScopes;
use Modules\Master\Tests\Support\MasterFixtures;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, AssetCatalogSeeder::class]);

    $vendor = MasterFixtures::vendor('BRI');
    $edc = Fx::edcModel();
    $paper = Fx::paperModel();
    $this->west = MasterFixtures::chain();
    $this->east = MasterFixtures::chain();
    $boss = Fx::approver();

    $this->westWarehouse = Fx::warehouse($this->west['service_point']);
    $this->eastWarehouse = Fx::warehouse($this->east['service_point']);
    $this->westTech = Fx::technician();
    $this->eastTech = Fx::technician();
    app(SyncUserLocationScopes::class)->handle($this->westTech, [$this->west['service_point']->id]);
    app(SyncUserLocationScopes::class)->handle($this->eastTech, [$this->east['service_point']->id]);

    // One asset in each warehouse, one with each technician, one installed in each region, one lost.
    [$inWest] = Fx::receive($edc, $vendor, $this->westWarehouse, ['W-STOCK'], $boss);
    [$inEast] = Fx::receive($edc, $vendor, $this->eastWarehouse, ['E-STOCK'], $boss);
    [$withWestTech, $installedWest] = Fx::receive($edc, $vendor, $this->westWarehouse, ['W-TECH', 'W-INST'], $boss);
    [$withEastTech, $installedEast] = Fx::receive($edc, $vendor, $this->eastWarehouse, ['E-TECH', 'E-INST'], $boss);
    [$lost] = Fx::receive($edc, $vendor, $this->westWarehouse, ['W-LOST'], $boss);

    Fx::issue($this->westWarehouse, $this->westTech, [$withWestTech, $installedWest], $boss);
    Fx::issue($this->eastWarehouse, $this->eastTech, [$withEastTech, $installedEast], $boss);

    foreach ([[$installedWest, $this->westTech, $this->west], [$installedEast, $this->eastTech, $this->east]] as [$asset, $tech, $region]) {
        app(MoveAssets::class)->handle(new MovementCommand(
            MovementType::Install, [MoveItem::asset($asset->fresh())], Holder::user($tech), Holder::terminal(MasterFixtures::terminalIn($vendor, $region['service_point'])), $tech,
        ));
    }

    app(MoveAssets::class)->handle(new MovementCommand(
        MovementType::SendToRepair, [MoveItem::asset($lost)], Holder::warehouse($this->westWarehouse), Holder::external(), $boss,
    ));

    Fx::receiveStock($paper, $vendor, $this->westWarehouse, 10, $boss);
    Fx::receiveStock($paper, $vendor, $this->eastWarehouse, 20, $boss);
    $this->boss = $boss;
});

function serials(object $query): array
{
    $serials = $query->pluck('serial_number')->all();
    sort($serials);

    return $serials;
}

it('shows a regional coordinator only what is in their own region', function () {
    $coordinator = User::factory()->create()->assignRole(RoleName::Coordinator->value);
    app(SyncUserLocationScopes::class)->handle($coordinator, [$this->west['city']->id]);

    expect(serials(Asset::query()->visibleTo($coordinator)))->toBe(['W-INST', 'W-STOCK', 'W-TECH'])
        ->and(AssetStock::query()->visibleTo($coordinator)->count())->toBe(1);
});

it('sees technician-held assets through the area the technician works in', function () {
    $coordinator = User::factory()->create()->assignRole(RoleName::Coordinator->value);
    app(SyncUserLocationScopes::class)->handle($coordinator, [$this->east['province']->id]);

    expect(serials(Asset::query()->visibleTo($coordinator)))->toContain('E-TECH')->not->toContain('W-TECH');
});

it('hides assets that are outside the company custody from restricted users', function () {
    $coordinator = User::factory()->create()->assignRole(RoleName::Coordinator->value);
    app(SyncUserLocationScopes::class)->handle($coordinator, [$this->west['province']->id]);

    expect(serials(Asset::query()->visibleTo($coordinator)))->not->toContain('W-LOST');
});

it('shows everything, including assets in repair, to users who see every area', function () {
    $manager = User::factory()->create()->assignRole(RoleName::Managerial->value);

    expect(Asset::query()->visibleTo($manager)->count())->toBe(Asset::query()->count())
        ->and(serials(Asset::query()->visibleTo($manager)))->toContain('W-LOST');
});

it('shows nothing to a user with no area and no assets of their own', function () {
    $nobody = User::factory()->create()->assignRole(RoleName::Coordinator->value);

    expect(Asset::query()->visibleTo($nobody)->count())->toBe(0)
        ->and(AssetStock::query()->visibleTo($nobody)->count())->toBe(0);
});

it('always shows a technician what they hold themselves', function () {
    $loner = Fx::technician();
    [$asset] = Fx::receive(Fx::edcModel(), MasterFixtures::vendor('CIMB'), $this->westWarehouse, ['MINE'], $this->boss);
    Fx::issue($this->westWarehouse, $loner, [$asset], $this->boss);

    expect(serials(Asset::query()->visibleTo($loner)))->toBe(['MINE']);
});

it('limits handovers to those touching the user region', function () {
    $coordinator = User::factory()->create()->assignRole(RoleName::Coordinator->value);
    app(SyncUserLocationScopes::class)->handle($coordinator, [$this->west['province']->id]);

    [$westAsset] = Fx::receive(Fx::edcModel(), MasterFixtures::vendor('PAX'), $this->westWarehouse, ['H-W'], $this->boss);
    [$eastAsset] = Fx::receive(Fx::edcModel(), MasterFixtures::vendor('PAY'), $this->eastWarehouse, ['H-E'], $this->boss);
    $westHandover = Fx::offer($this->westWarehouse, $this->westTech, [MoveItem::asset($westAsset)], $this->boss);
    Fx::offer($this->eastWarehouse, $this->eastTech, [MoveItem::asset($eastAsset)], $this->boss);

    $pending = AssetHandover::query()->visibleTo($coordinator)->where('status', 'pending')->pluck('id')->all();

    expect($pending)->toBe([$westHandover->id]);
});

describe('confirming a handover into a warehouse', function () {
    beforeEach(function () {
        [$asset] = Fx::receive(Fx::edcModel(), MasterFixtures::vendor('BNI'), $this->westWarehouse, ['RET-1'], $this->boss);
        Fx::issue($this->westWarehouse, $this->westTech, [$asset], $this->boss);
        $this->handover = app(RequestHandover::class)->handle(new MovementCommand(
            MovementType::ReturnToWarehouse, [MoveItem::asset($asset->fresh())], Holder::user($this->westTech), Holder::warehouse($this->westWarehouse), $this->westTech,
        ));
    });

    it('accepts an approver whose area covers the warehouse', function () {
        $local = Fx::scopedApprover([$this->west['city']]);

        app(AcceptHandover::class)->handle($this->handover, $local);

        expect($this->handover->fresh()->resolved_by)->toBe($local->id);
    });

    it('refuses an approver working in another region', function () {
        $foreign = Fx::scopedApprover([$this->east['city']]);

        app(AcceptHandover::class)->handle($this->handover, $foreign);
    })->throws(AuthorizationException::class);
});
