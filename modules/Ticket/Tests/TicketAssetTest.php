<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Asset\Application\Actions\AcceptHandover;
use Modules\Asset\Application\Actions\MoveAssets;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Application\Services\StockBook;
use Modules\Asset\Database\Seeders\AssetCatalogSeeder;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Asset\Domain\Models\AssetMovement;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Asset\Tests\Support\AssetFixtures as Assets;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Master\Application\Actions\DeleteTerminal;
use Modules\Master\Domain\Models\Terminal;
use Modules\Master\Domain\Models\Vendor;
use Modules\Master\Tests\Support\MasterFixtures;
use Modules\Ticket\Application\Actions\AcceptTicket;
use Modules\Ticket\Application\Actions\ArriveAtTicket;
use Modules\Ticket\Application\Actions\AssignTicket;
use Modules\Ticket\Application\Actions\CloseVisit;
use Modules\Ticket\Application\Actions\CreateTicket;
use Modules\Ticket\Application\Actions\RecordAssetLine;
use Modules\Ticket\Application\Actions\RemoveAssetLine;
use Modules\Ticket\Application\Actions\StartHandling;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Database\Seeders\TicketAssetRulesSeeder;
use Modules\Ticket\Database\Seeders\TicketCatalogSeeder;
use Modules\Ticket\Domain\Enums\AssetLineAction;
use Modules\Ticket\Domain\Enums\ReasonType;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketAssetLine;
use Modules\Ticket\Tests\Support\TicketFixtures as Fx;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, TicketCatalogSeeder::class, AssetCatalogSeeder::class, TicketAssetRulesSeeder::class]);
    $this->tech = Fx::technician();
    $this->terminal = Fx::terminal();
    $this->vendor = $this->terminal->vendor;
    $this->warehouse = Assets::warehouse();
});

/**
 * An EDC that the technician carries, ready to be installed.
 */
function carried(object $test, ?User $technician = null, ?int $vendorId = null): Asset
{
    $technician ??= $test->tech;
    $vendor = $vendorId === null ? $test->vendor : Vendor::query()->findOrFail($vendorId);
    [$asset] = Assets::receive(Assets::edcModel(), $vendor, $test->warehouse);
    Assets::issue($test->warehouse, $technician, [$asset]);

    return $asset->fresh();
}

/**
 * An EDC already standing at the test terminal.
 */
function standingAtTerminal(object $test): Asset
{
    $asset = carried($test, Fx::technician());
    $owner = User::query()->findOrFail($asset->holder_id);

    app(MoveAssets::class)->handle(new MovementCommand(MovementType::Install, [MoveItem::asset($asset)], Holder::user($owner), Holder::terminal($test->terminal), $owner));

    return $asset->fresh();
}

function workingOn(object $test, string $type): Ticket
{
    $ticket = app(CreateTicket::class)->handle($test->terminal, Fx::type($type), Fx::dispatcher());
    $ticket = app(AssignTicket::class)->handle($ticket, $test->tech, Fx::dispatcher());
    $ticket = app(AcceptTicket::class)->handle($ticket, $test->tech);
    $ticket = app(ArriveAtTicket::class)->handle($ticket, $test->tech);

    return app(StartHandling::class)->handle($ticket, $test->tech);
}

function note(object $test, Ticket $ticket, AssetLineAction $action, ?Asset $asset = null, ?AssetModel $model = null, int $quantity = 1, ?string $uuid = null): TicketAssetLine
{
    return app(RecordAssetLine::class)->handle($ticket, $test->tech, $action, $asset, $model, $quantity, context: new ActionContext(clientUuid: $uuid));
}

describe('applying assets when a visit finishes', function () {
    it('swaps the machine: the old one comes back to the technician, the new one is installed, both in the ledger', function () {
        $old = standingAtTerminal($this);
        $new = carried($this);
        $ticket = workingOn($this, 'REPLACE');

        note($this, $ticket, AssetLineAction::Uninstall, $old);
        note($this, $ticket, AssetLineAction::Install, $new);

        // Nothing has moved while the visit is still open.
        expect($new->fresh()->status)->toBe(AssetStatus::WithTechnician)
            ->and($old->fresh()->status)->toBe(AssetStatus::Installed);

        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Finish);

        $new = $new->fresh();
        $old = $old->fresh();
        $lines = TicketAssetLine::query()->where('ticket_id', $ticket->id)->get();

        expect($ticket->fresh()->status)->toBe(TicketStatus::Finished)
            ->and($new->status)->toBe(AssetStatus::Installed)
            ->and($new->holder_type)->toBe(HolderType::Terminal)
            ->and($new->holder_id)->toBe($this->terminal->id)
            ->and($old->status)->toBe(AssetStatus::WithTechnician)
            ->and($old->holder_id)->toBe($this->tech->id)
            ->and($lines->every(fn (TicketAssetLine $line): bool => $line->isApplied()))->toBeTrue();

        $movement = AssetMovement::query()->findOrFail($lines->first()->applied_movement_id);
        expect($movement->reference_type)->toBe('ticket')->and($movement->reference_id)->toBe($ticket->id);
    });

    it('uses up consumables from what the technician carries', function () {
        $paper = Assets::paperModel();
        Assets::receiveStock($paper, $this->vendor, $this->warehouse, 10);
        app(AcceptHandover::class)->handle(Assets::offer($this->warehouse, $this->tech, [MoveItem::stock($paper, $this->vendor, 10)]), $this->tech);
        $ticket = workingOn($this, 'MAINTENANCE');

        note($this, $ticket, AssetLineAction::Consume, model: $paper, quantity: 3);
        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Finish);

        expect(app(StockBook::class)->available($paper->id, $this->vendor->id, Holder::user($this->tech)))->toBe(7);
    });

    it('applies nothing when the visit ends pending, and keeps the notes as void', function () {
        $new = carried($this);
        $ticket = workingOn($this, 'INSTALL');
        $line = note($this, $ticket, AssetLineAction::Install, $new);

        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Pending, reason: Fx::reason(ReasonType::Pending, 'MERCHANT_NOT_READY'));

        expect($new->fresh()->status)->toBe(AssetStatus::WithTechnician)
            ->and($line->fresh()->voided_at)->not->toBeNull()
            ->and($line->fresh()->applied_movement_id)->toBeNull();
    });

    it('fails the whole close, leaving everything as it was, when the ledger refuses a line', function () {
        $new = carried($this);
        $ticket = workingOn($this, 'INSTALL');
        note($this, $ticket, AssetLineAction::Install, $new);

        // Meanwhile the unit is declared lost: the line was valid when noted but is not any more.
        app(MoveAssets::class)->handle(new MovementCommand(MovementType::MarkLost, [MoveItem::asset($new)], Holder::user($this->tech), Holder::external(), Assets::approver()));

        expect(fn () => app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Finish))->toThrow(ValidationException::class);

        expect($ticket->fresh()->status)->toBe(TicketStatus::Handling)
            ->and($ticket->visits()->sole()->outcome)->toBeNull()
            ->and(TicketAssetLine::query()->whereNotNull('applied_movement_id')->count())->toBe(0);
    });
});

describe('required assets', function () {
    it('keeps a replacement from finishing until both the old and the new machine are recorded', function () {
        $ticket = workingOn($this, 'REPLACE');
        note($this, $ticket, AssetLineAction::Install, carried($this));

        try {
            app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Finish);
            $this->fail('It should not have closed.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['assets'][0])->toContain('Ditarik: butuh 1, baru ada 0')->not->toContain('Dipasang');
        }

        expect($ticket->fresh()->status)->toBe(TicketStatus::Handling);
    });

    it('asks nothing of a maintenance visit', function () {
        $ticket = workingOn($this, 'MAINTENANCE');

        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Finish);

        expect($ticket->fresh()->status)->toBe(TicketStatus::Finished);
    });
});

describe('what may be noted', function () {
    it('refuses an asset that belongs to another vendor', function () {
        $foreign = carried($this, vendorId: MasterFixtures::vendor('OTHER-'.uniqid())->id);
        $ticket = workingOn($this, 'INSTALL');

        expect(fn () => note($this, $ticket, AssetLineAction::Install, $foreign))->toThrow(ValidationException::class, 'milik vendor lain');
    });

    it('refuses to install something the technician does not carry', function () {
        [$inStock] = Assets::receive(Assets::edcModel(), $this->vendor, $this->warehouse);
        $ticket = workingOn($this, 'INSTALL');

        expect(fn () => note($this, $ticket, AssetLineAction::Install, $inStock))->toThrow(ValidationException::class, 'tidak berada pada teknisi');
    });

    it('refuses to pull out a machine that stands at another TID', function () {
        $elsewhere = carried($this, Fx::technician());
        $owner = User::query()->findOrFail($elsewhere->holder_id);
        $other = MasterFixtures::terminalIn($this->vendor, MasterFixtures::chain()['area']);
        app(MoveAssets::class)->handle(new MovementCommand(MovementType::Install, [MoveItem::asset($elsewhere)], Holder::user($owner), Holder::terminal($other), $owner));
        $ticket = workingOn($this, 'PULLOUT');

        expect(fn () => note($this, $ticket, AssetLineAction::Uninstall, $elsewhere->fresh()))->toThrow(ValidationException::class, 'tidak terpasang di TID');
    });

    it('refuses to use more consumables than the technician carries', function () {
        $paper = Assets::paperModel();
        Assets::receiveStock($paper, $this->vendor, $this->warehouse, 5);
        app(AcceptHandover::class)->handle(Assets::offer($this->warehouse, $this->tech, [MoveItem::stock($paper, $this->vendor, 5)]), $this->tech);
        $ticket = workingOn($this, 'MAINTENANCE');
        note($this, $ticket, AssetLineAction::Consume, model: $paper, quantity: 4);

        expect(fn () => note($this, $ticket, AssetLineAction::Consume, model: $paper, quantity: 2))->toThrow(ValidationException::class, 'tidak cukup');
    });

    it('refuses to list the same unit twice on one visit', function () {
        $new = carried($this);
        $ticket = workingOn($this, 'INSTALL');
        note($this, $ticket, AssetLineAction::Install, $new);

        expect(fn () => note($this, $ticket, AssetLineAction::Install, $new))->toThrow(ValidationException::class, 'sudah dicatat');
    });

    it('accepts notes only while the ticket is being worked on', function () {
        $new = carried($this);
        $ticket = Fx::arrived(technician: $this->tech);

        expect(fn () => note($this, $ticket, AssetLineAction::Install, $new))->toThrow(ValidationException::class, 'sedang dikerjakan');
    });

    it('returns the same line when a phone resends it', function () {
        $new = carried($this);
        $ticket = workingOn($this, 'INSTALL');
        $uuid = (string) Str::uuid();

        $first = note($this, $ticket, AssetLineAction::Install, $new, uuid: $uuid);
        $second = note($this, $ticket, AssetLineAction::Install, $new, uuid: $uuid);

        expect($second->id)->toBe($first->id)->and(TicketAssetLine::query()->count())->toBe(1);
    });

    it('lets the technician take back a mistaken line while the visit is open', function () {
        $new = carried($this);
        $ticket = workingOn($this, 'INSTALL');
        $line = note($this, $ticket, AssetLineAction::Install, $new);

        app(RemoveAssetLine::class)->handle($ticket, $line, $this->tech);

        expect(TicketAssetLine::query()->count())->toBe(0);
    });
});

describe('mobile api', function () {
    it('records, lists and removes lines for the technician of the visit', function () {
        $new = carried($this);
        $old = standingAtTerminal($this);
        $ticket = workingOn($this, 'REPLACE');
        Sanctum::actingAs($this->tech, ['*']);
        $base = "/api/mobile/v1/tickets/{$ticket->ulid}/assets";
        $uuid = (string) Str::uuid();

        $created = $this->postJson($base, ['action' => 'install', 'asset' => $new->ulid, 'client_uuid' => $uuid])->assertCreated();
        $this->postJson($base, ['action' => 'install', 'asset' => $new->ulid, 'client_uuid' => $uuid])->assertOk();

        $index = $this->getJson($base)->assertOk();
        expect($index->json('data.lines'))->toHaveCount(1)
            ->and(collect($index->json('data.installed_here'))->pluck('serial_number')->all())->toBe([$old->serial_number])
            ->and($index->json('data.lines.0.status'))->toBe('planned');

        $this->deleteJson("{$base}/{$created->json('data.id')}")->assertNoContent();
        expect($this->getJson($base)->json('data.lines'))->toHaveCount(0);
    });

    it('answers 404 to a technician who never had the ticket', function () {
        $ticket = workingOn($this, 'INSTALL');
        Sanctum::actingAs(Fx::technician(), ['*']);

        $this->getJson("/api/mobile/v1/tickets/{$ticket->ulid}/assets")->assertNotFound();
    });

    it('reports a business refusal as a validation error', function () {
        [$inStock] = Assets::receive(Assets::edcModel(), $this->vendor, $this->warehouse);
        $ticket = workingOn($this, 'INSTALL');
        Sanctum::actingAs($this->tech, ['*']);

        $this->postJson("/api/mobile/v1/tickets/{$ticket->ulid}/assets", ['action' => 'install', 'asset' => $inStock->ulid])
            ->assertUnprocessable()->assertJsonValidationErrors('asset');
    });
});

describe('deleting a TID', function () {
    it('is refused while an asset is installed on it', function () {
        standingAtTerminal($this);

        expect(fn () => app(DeleteTerminal::class)->handle($this->terminal))->toThrow(ValidationException::class, 'aset terpasang');
        expect(Terminal::query()->whereKey($this->terminal->id)->exists())->toBeTrue();
    });

    it('is refused while a ticket on it is still open', function () {
        Fx::ticket($this->terminal);

        expect(fn () => app(DeleteTerminal::class)->handle($this->terminal))->toThrow(ValidationException::class, 'tiket yang belum selesai');
    });

    it('is allowed for a quiet TID', function () {
        app(DeleteTerminal::class)->handle($this->terminal);

        expect(Terminal::query()->whereKey($this->terminal->id)->exists())->toBeFalse();
    });
});
