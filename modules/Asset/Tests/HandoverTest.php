<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Application\Actions\AcceptHandover;
use Modules\Asset\Application\Actions\CancelHandover;
use Modules\Asset\Application\Actions\MoveAssets;
use Modules\Asset\Application\Actions\RejectHandover;
use Modules\Asset\Application\Actions\RequestHandover;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Database\Seeders\AssetCatalogSeeder;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Enums\HandoverStatus;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Models\AssetMovement;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Asset\Tests\Support\AssetFixtures as Fx;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Master\Tests\Support\MasterFixtures;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, AssetCatalogSeeder::class]);
    $this->vendor = MasterFixtures::vendor('BRI');
    $this->warehouse = Fx::warehouse();
    $this->tech = Fx::technician();
    $this->leader = Fx::approver();
    [$this->a, $this->b] = Fx::receive(Fx::edcModel(), $this->vendor, $this->warehouse, ['SN-A', 'SN-B']);
});

it('moves nothing until the receiver confirms', function () {
    $handover = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a), MoveItem::asset($this->b)], $this->leader);

    expect($handover->status)->toBe(HandoverStatus::Pending)
        ->and($handover->items)->toHaveCount(2)
        ->and($this->a->fresh()->status)->toBe(AssetStatus::InTransit)
        ->and($this->a->fresh()->pending_handover_id)->toBe($handover->id)
        // Custody has not changed: the warehouse is still the holder until the technician accepts.
        ->and($this->a->fresh()->holder_type)->toBe(HolderType::Warehouse)
        ->and(AssetMovement::query()->where('type', MovementType::IssueToTechnician)->count())->toBe(0);
});

it('transfers custody and writes the ledger when the technician accepts', function () {
    $handover = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)], $this->leader);

    $movement = app(AcceptHandover::class)->handle($handover, $this->tech, 'Diterima baik');

    $asset = $this->a->fresh();

    expect($asset->status)->toBe(AssetStatus::WithTechnician)
        ->and($asset->holder_type)->toBe(HolderType::User)
        ->and($asset->holder_id)->toBe($this->tech->id)
        ->and($asset->pending_handover_id)->toBeNull()
        ->and($asset->last_movement_id)->toBe($movement->id)
        ->and($movement->type)->toBe(MovementType::IssueToTechnician)
        ->and($movement->handover_id)->toBe($handover->id)
        ->and($movement->actor_id)->toBe($this->tech->id)
        ->and($movement->from_holder_id)->toBe($this->warehouse->id)
        ->and($handover->fresh()->status)->toBe(HandoverStatus::Accepted)
        ->and($handover->fresh()->movement_id)->toBe($movement->id)
        ->and($handover->fresh()->resolved_by)->toBe($this->tech->id)
        ->and($this->b->fresh()->status)->toBe(AssetStatus::InStock);
});

it('will not let a handover be offered twice', function () {
    Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)]);

    Fx::offer($this->warehouse, Fx::technician(), [MoveItem::asset($this->a)]);
})->throws(ValidationException::class, 'menunggu serah-terima');

it('will not let an asset in a pending handover be moved by anything else', function () {
    Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)]);

    app(MoveAssets::class)->handle(new MovementCommand(
        MovementType::SendToRepair, [MoveItem::asset($this->a)], Holder::warehouse($this->warehouse), Holder::external(), $this->leader,
    ));
})->throws(ValidationException::class, 'menunggu serah-terima');

it('refuses to move custody directly without the receiver confirming', function () {
    app(MoveAssets::class)->handle(new MovementCommand(
        MovementType::IssueToTechnician, [MoveItem::asset($this->a)], Holder::warehouse($this->warehouse), Holder::user($this->tech), $this->leader,
    ));
})->throws(ValidationException::class, 'serah-terima');

describe('who may answer', function () {
    it('lets only the receiving technician accept', function (string $who) {
        $handover = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)], $this->leader);

        $actor = match ($who) {
            'another technician' => Fx::technician(),
            'the sender' => $this->leader,
            'a super admin' => User::factory()->create()->assignRole(RoleName::SuperAdmin->value),
        };

        app(AcceptHandover::class)->handle($handover, $actor);
    })->with(['another technician', 'the sender', 'a super admin'])->throws(AuthorizationException::class);

    it('leaves the assets untouched after a refused attempt', function () {
        $handover = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)]);

        try {
            app(AcceptHandover::class)->handle($handover, Fx::technician());
        } catch (AuthorizationException) {
        }

        expect($this->a->fresh()->status)->toBe(AssetStatus::InTransit)
            ->and($handover->fresh()->status)->toBe(HandoverStatus::Pending);
    });

    it('requires the recipient to be a technician allowed to receive assets', function () {
        $office = User::factory()->create()->assignRole(RoleName::Coordinator->value);

        Fx::offer($this->warehouse, $office, [MoveItem::asset($this->a)]);
    })->throws(ValidationException::class, 'bukan teknisi');

    it('refuses an inactive recipient', function () {
        $this->tech->update(['is_active' => false]);

        Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)]);
    })->throws(ValidationException::class);
});

describe('turning a handover down', function () {
    it('returns the assets to where they were and records no movement', function () {
        $handover = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)]);
        $movementsBefore = AssetMovement::query()->count();

        app(RejectHandover::class)->handle($handover, $this->tech, 'Layar retak');

        $asset = $this->a->fresh();

        expect($asset->status)->toBe(AssetStatus::InStock)
            ->and($asset->holder_type)->toBe(HolderType::Warehouse)
            ->and($asset->pending_handover_id)->toBeNull()
            ->and($handover->fresh()->status)->toBe(HandoverStatus::Rejected)
            ->and($handover->fresh()->resolution_note)->toBe('Layar retak')
            ->and(AssetMovement::query()->count())->toBe($movementsBefore);
    });

    it('requires a reason', function () {
        $handover = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)]);

        app(RejectHandover::class)->handle($handover, $this->tech, '  ');
    })->throws(ValidationException::class, 'Alasan');

    it('lets the sender withdraw it, and an approver too, but not a stranger', function () {
        $handover = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)], $this->leader);

        expect(fn () => app(CancelHandover::class)->handle($handover, Fx::technician()))->toThrow(AuthorizationException::class);

        app(CancelHandover::class)->handle($handover, $this->leader);

        expect($handover->fresh()->status)->toBe(HandoverStatus::Cancelled)
            ->and($this->a->fresh()->status)->toBe(AssetStatus::InStock);

        $second = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)], Fx::approver());
        app(CancelHandover::class)->handle($second, $this->leader);

        expect($second->fresh()->status)->toBe(HandoverStatus::Cancelled);
    });

    it('can be offered again after it was turned down', function () {
        $handover = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)]);
        app(RejectHandover::class)->handle($handover, $this->tech, 'Salah barang');

        Fx::issue($this->warehouse, $this->tech, [$this->a]);

        expect($this->a->fresh()->holder_id)->toBe($this->tech->id);
    });

    it('cannot be answered twice', function () {
        $handover = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)]);
        app(AcceptHandover::class)->handle($handover, $this->tech);

        app(AcceptHandover::class)->handle($handover, $this->tech);
    })->throws(ValidationException::class, 'sudah diselesaikan');

    it('cannot be accepted after it was cancelled', function () {
        $handover = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)], $this->leader);
        app(CancelHandover::class)->handle($handover, $this->leader);

        app(AcceptHandover::class)->handle($handover, $this->tech);
    })->throws(ValidationException::class, 'sudah diselesaikan');
});

describe('between technicians and back to a warehouse', function () {
    it('passes an asset from one technician to another', function () {
        Fx::issue($this->warehouse, $this->tech, [$this->a]);
        $other = Fx::technician();

        $handover = app(RequestHandover::class)->handle(new MovementCommand(
            MovementType::TransferBetweenTechnicians, [MoveItem::asset($this->a->fresh())], Holder::user($this->tech), Holder::user($other), $this->tech,
        ));
        app(AcceptHandover::class)->handle($handover, $other);

        expect($this->a->fresh()->holder_id)->toBe($other->id)
            ->and($this->a->fresh()->status)->toBe(AssetStatus::WithTechnician);
    });

    it('will not hand over what the sender does not hold', function () {
        Fx::issue($this->warehouse, $this->tech, [$this->a]);

        app(RequestHandover::class)->handle(new MovementCommand(
            MovementType::TransferBetweenTechnicians, [MoveItem::asset($this->a->fresh())], Holder::user(Fx::technician()), Holder::user(Fx::technician()), $this->leader,
        ));
    })->throws(ValidationException::class, 'tidak berada di pemegang asal');

    it('returns an asset to a warehouse once an approver there confirms', function () {
        Fx::issue($this->warehouse, $this->tech, [$this->a]);

        $handover = app(RequestHandover::class)->handle(new MovementCommand(
            MovementType::ReturnToWarehouse, [MoveItem::asset($this->a->fresh())], Holder::user($this->tech), Holder::warehouse($this->warehouse), $this->tech,
        ));
        app(AcceptHandover::class)->handle($handover, $this->leader);

        expect($this->a->fresh()->status)->toBe(AssetStatus::InStock)
            ->and($this->a->fresh()->holder_type)->toBe(HolderType::Warehouse);
    });

    it('refuses a warehouse confirmation from someone without approval rights', function () {
        Fx::issue($this->warehouse, $this->tech, [$this->a]);

        $handover = app(RequestHandover::class)->handle(new MovementCommand(
            MovementType::ReturnToWarehouse, [MoveItem::asset($this->a->fresh())], Holder::user($this->tech), Holder::warehouse($this->warehouse), $this->tech,
        ));

        app(AcceptHandover::class)->handle($handover, User::factory()->create()->assignRole(RoleName::Coordinator->value));
    })->throws(AuthorizationException::class);

    it('moves an asset between warehouses', function () {
        $other = Fx::warehouse();

        $handover = app(RequestHandover::class)->handle(new MovementCommand(
            MovementType::TransferBetweenWarehouses, [MoveItem::asset($this->a)], Holder::warehouse($this->warehouse), Holder::warehouse($other), $this->leader,
        ));
        app(AcceptHandover::class)->handle($handover, $this->leader);

        expect($this->a->fresh()->holder_id)->toBe($other->id);
    });

    it('refuses a transfer to the same place', function () {
        app(RequestHandover::class)->handle(new MovementCommand(
            MovementType::TransferBetweenWarehouses, [MoveItem::asset($this->a)], Holder::warehouse($this->warehouse), Holder::warehouse($this->warehouse), $this->leader,
        ));
    })->throws(ValidationException::class, 'sama dengan asal');
});

it('refuses a movement whose endpoints do not suit its type', function () {
    app(RequestHandover::class)->handle(new MovementCommand(
        MovementType::IssueToTechnician, [MoveItem::asset($this->a)], Holder::user($this->tech), Holder::warehouse($this->warehouse), $this->leader,
    ));
})->throws(ValidationException::class, 'tidak dapat berasal');

it('refuses to offer an asset that is not in the sender warehouse', function () {
    $elsewhere = Fx::warehouse();

    app(RequestHandover::class)->handle(new MovementCommand(
        MovementType::IssueToTechnician, [MoveItem::asset($this->a)], Holder::warehouse($elsewhere), Holder::user($this->tech), $this->leader,
    ));
})->throws(ValidationException::class, 'tidak berada di pemegang asal');

it('refuses the same asset twice in one request', function () {
    Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a), MoveItem::asset($this->a)]);
})->throws(ValidationException::class, 'lebih dari sekali');

it('refuses an empty request', function () {
    Fx::offer($this->warehouse, $this->tech, []);
})->throws(ValidationException::class, 'minimal satu');
