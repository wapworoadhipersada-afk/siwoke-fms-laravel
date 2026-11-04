<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Asset\Application\Actions\AcceptHandover;
use Modules\Asset\Application\Actions\RequestHandover;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Database\Seeders\AssetCatalogSeeder;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Models\AssetMovement;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Asset\Tests\Support\AssetFixtures as Fx;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Master\Tests\Support\MasterFixtures;

const MOBILE_BASE = '/api/mobile/v1';

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, AssetCatalogSeeder::class]);

    $this->vendor = MasterFixtures::vendor('BRI');
    $this->warehouse = Fx::warehouse();
    $this->boss = Fx::approver();
    $this->tech = Fx::technician();
    $this->other = Fx::technician();
    $this->paper = Fx::paperModel();

    [$this->mine, $this->theirs, $this->waiting] = Fx::receive(Fx::edcModel(), $this->vendor, $this->warehouse, ['SN-MINE', 'SN-THEIRS', 'SN-WAIT'], $this->boss);
    Fx::receiveStock($this->paper, $this->vendor, $this->warehouse, 50, $this->boss);

    Fx::issue($this->warehouse, $this->tech, [$this->mine], $this->boss);
    Fx::issue($this->warehouse, $this->other, [$this->theirs], $this->boss);
});

function asTechnician(object $user): void
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($user, ['mobile']);
}

describe('what I hold', function () {
    it('lists my own units and consumable balances only', function () {
        app(AcceptHandover::class)->handle(
            Fx::offer($this->warehouse, $this->tech, [MoveItem::stock($this->paper, $this->vendor, 12)], $this->boss),
            $this->tech,
        );

        asTechnician($this->tech);

        $this->getJson(MOBILE_BASE.'/me/assets')
            ->assertOk()
            ->assertJsonCount(1, 'data.assets')
            ->assertJsonPath('data.assets.0.serial_number', 'SN-MINE')
            ->assertJsonPath('data.assets.0.category', 'EDC')
            ->assertJsonCount(1, 'data.stock')
            ->assertJsonPath('data.stock.0.quantity', 12);
    });

    it('requires a signed-in mobile user', function () {
        $this->getJson(MOBILE_BASE.'/me/assets')->assertUnauthorized();
    });

    it('is closed to people who are not allowed to see assets', function () {
        $nobody = User::factory()->create();
        asTechnician($nobody);

        $this->getJson(MOBILE_BASE.'/me/assets')->assertForbidden();
    });
});

describe('handovers waiting for me', function () {
    beforeEach(function () {
        $this->offer = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->waiting), MoveItem::stock($this->paper, $this->vendor, 5)], $this->boss);
    });

    it('lists what is offered to me and nothing offered to others', function () {
        Fx::offer($this->warehouse, $this->other, [MoveItem::stock($this->paper, $this->vendor, 1)], $this->boss);

        asTechnician($this->tech);

        $this->getJson(MOBILE_BASE.'/handovers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->offer->ulid)
            ->assertJsonPath('data.0.type', 'issue_to_technician')
            ->assertJsonCount(2, 'data.0.items')
            ->assertJsonPath('data.0.items.0.serial_number', 'SN-WAIT')
            ->assertJsonPath('data.0.items.1.quantity', 5);
    });

    it('moves custody when I accept, recording where I was', function () {
        asTechnician($this->tech);

        $this->postJson(MOBILE_BASE."/handovers/{$this->offer->ulid}/accept", ['note' => 'Lengkap', 'latitude' => -6.9175, 'longitude' => 107.6191])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $movement = AssetMovement::query()->where('handover_id', $this->offer->id)->sole();

        expect($this->waiting->fresh()->holder_id)->toBe($this->tech->id)
            ->and($this->waiting->fresh()->status)->toBe(AssetStatus::WithTechnician)
            ->and($movement->actor_id)->toBe($this->tech->id)
            ->and($movement->latitude)->toBe('-6.9175000')
            ->and($movement->notes)->toBe('Lengkap');

        $this->getJson(MOBILE_BASE.'/handovers')->assertJsonCount(0, 'data');
    });

    it('refuses someone else accepting it for me', function () {
        asTechnician($this->other);

        $this->postJson(MOBILE_BASE."/handovers/{$this->offer->ulid}/accept")
            ->assertForbidden()
            ->assertJsonPath('code', 'not_the_receiver');

        expect($this->waiting->fresh()->status)->toBe(AssetStatus::InTransit);
    });

    it('cannot be accepted twice', function () {
        asTechnician($this->tech);
        $this->postJson(MOBILE_BASE."/handovers/{$this->offer->ulid}/accept")->assertOk();

        $this->postJson(MOBILE_BASE."/handovers/{$this->offer->ulid}/accept")->assertUnprocessable();
    });

    it('sends the assets back when I reject, and needs a reason', function () {
        asTechnician($this->tech);

        $this->postJson(MOBILE_BASE."/handovers/{$this->offer->ulid}/reject", [])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->postJson(MOBILE_BASE."/handovers/{$this->offer->ulid}/reject", ['reason' => 'Layar retak'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        expect($this->waiting->fresh()->status)->toBe(AssetStatus::InStock)
            ->and($this->waiting->fresh()->holder_type)->toBe(HolderType::Warehouse)
            ->and(AssetMovement::query()->where('handover_id', $this->offer->id)->exists())->toBeFalse();
    });

    it('is closed to office users who cannot receive assets', function () {
        $coordinator = User::factory()->create()->assignRole(RoleName::Coordinator->value);
        asTechnician($coordinator);

        $this->getJson(MOBILE_BASE.'/handovers')->assertForbidden();
    });

    it('never offers a non-existent handover', function () {
        asTechnician($this->tech);

        $this->postJson(MOBILE_BASE.'/handovers/01ARZ3NDEKTSV4RRFFQ69G5FAV/accept')->assertNotFound();
    });
});

it('keeps a transfer between technicians off the other technician list until they are the receiver', function () {
    $handover = app(RequestHandover::class)->handle(new MovementCommand(
        MovementType::TransferBetweenTechnicians,
        [MoveItem::asset($this->mine->fresh())],
        Holder::user($this->tech),
        Holder::user($this->other),
        $this->tech,
    ));

    asTechnician($this->tech);
    $this->getJson(MOBILE_BASE.'/handovers')->assertJsonCount(0, 'data');

    asTechnician($this->other);
    $this->getJson(MOBILE_BASE.'/handovers')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $handover->ulid);
});
