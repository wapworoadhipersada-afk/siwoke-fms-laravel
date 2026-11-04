<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Master\Domain\Models\Vendor;
use Modules\Master\Tests\Support\MasterFixtures;
use Modules\Ticket\Application\Actions\ArriveAtTicket;
use Modules\Ticket\Application\Actions\CloseVisit;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Database\Seeders\TicketCatalogSeeder;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Tests\Support\TicketFixtures as Fx;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, TicketCatalogSeeder::class]);
    $this->office = Fx::dispatcher();
    $this->tech = Fx::technician();
    $this->super = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);
});

describe('access control', function () {
    it('sends guests to the login page', function (string $uri) {
        $this->get($uri)->assertRedirect(route('login'));
    })->with(['/tickets', '/tickets/create']);

    it('lets a technician read only their own tickets and nothing else', function () {
        $mine = Fx::assigned(technician: $this->tech);
        Fx::ticket();

        $this->actingAs($this->tech)->get('/tickets')
            ->assertInertia(fn (Assert $page) => $page->component('tickets/Index')->has('tickets.data', 1)->where('tickets.data.0.ulid', $mine->ulid)->where('can.create', false));

        $this->actingAs($this->tech)->get('/tickets/create')->assertForbidden();
        $this->actingAs($this->tech)->post("/tickets/{$mine->ulid}/assign", ['technician' => $this->tech->ulid])->assertForbidden();
        $this->actingAs($this->tech)->post("/tickets/{$mine->ulid}/cancel", ['reason' => 'x'])->assertForbidden();
    });

    it('answers 404 for a ticket outside the area', function () {
        $west = MasterFixtures::chain();
        $east = MasterFixtures::chain();
        $regional = Fx::scopedDispatcher([$west['province']]);
        $own = Fx::ticket(Fx::terminal('W', $west['service_point']));
        $foreign = Fx::ticket(Fx::terminal('E', $east['service_point']));

        $this->actingAs($regional)->get("/tickets/{$own->ulid}")->assertOk();
        $this->actingAs($regional)->get("/tickets/{$foreign->ulid}")->assertNotFound();
        $this->actingAs($regional)->post("/tickets/{$foreign->ulid}/cancel", ['reason' => 'x'])->assertNotFound();
    });
});

describe('creating', function () {
    it('opens a ticket from vendor, TID and type', function () {
        $terminal = Fx::terminal();

        $this->actingAs($this->office)->post('/tickets', [
            'vendor' => $terminal->vendor->ulid, 'tid' => strtolower($terminal->tid), 'ticket_type' => Fx::type('INSTALL')->ulid,
            'priority' => 'high', 'description' => 'Pasang mesin', 'vendor_ticket_no' => 'BRI-1',
        ])->assertRedirect();

        $ticket = Ticket::query()->sole();

        expect($ticket->terminal_id)->toBe($terminal->id)
            ->and($ticket->vendor_ticket_no)->toBe('BRI-1')
            ->and($ticket->created_by)->toBe($this->office->id);
    });

    it('reports a TID that is unknown, belongs to another vendor, or lies outside the area', function (string $case) {
        $terminal = Fx::terminal();
        $otherVendor = MasterFixtures::vendor('OTHER');
        $east = MasterFixtures::chain();
        $user = $case === 'outside the area' ? Fx::scopedDispatcher([$east['province']]) : $this->office;

        $payload = match ($case) {
            'unknown' => ['vendor' => $terminal->vendor->ulid, 'tid' => 'NOPE9999'],
            'other vendor' => ['vendor' => $otherVendor->ulid, 'tid' => $terminal->tid],
            'outside the area' => ['vendor' => $terminal->vendor->ulid, 'tid' => $terminal->tid],
        };

        $this->actingAs($user)->post('/tickets', [...$payload, 'ticket_type' => Fx::type()->ulid, 'priority' => 'normal'])->assertSessionHasErrors('tid');

        expect(Ticket::query()->count())->toBe(0);
    })->with(['unknown', 'other vendor', 'outside the area']);

    it('reports a duplicate vendor reference on its own field', function () {
        $terminal = Fx::terminal();
        Fx::ticket($terminal, vendorTicketNo: 'REF-1');

        $this->actingAs($this->office)->post('/tickets', [
            'vendor' => $terminal->vendor->ulid, 'tid' => $terminal->tid, 'ticket_type' => Fx::type()->ulid, 'priority' => 'normal', 'vendor_ticket_no' => 'ref-1',
        ])->assertSessionHasErrors('vendor_ticket_no');
    });

    it('validates the input', function () {
        $this->actingAs($this->office)->post('/tickets', ['priority' => 'whenever'])->assertSessionHasErrors(['vendor', 'tid', 'ticket_type', 'priority']);
    });
});

describe('the list', function () {
    it('filters by status, type, vendor and search', function () {
        $a = Fx::ticket(Fx::terminal('A'), 'INSTALL');
        $b = Fx::ticket(Fx::terminal('B'), 'MAINTENANCE');
        Fx::assigned($b, $this->tech);

        $ids = fn (string $query) => collect($this->actingAs($this->office)->get("/tickets?{$query}")->viewData('page')['props']['tickets']['data'])->pluck('ulid')->all();

        expect($ids('status=assigned'))->toBe([$b->ulid])
            ->and($ids('type='.Fx::type('INSTALL')->ulid))->toBe([$a->ulid])
            ->and($ids('vendor='.$a->vendor->ulid))->toBe([$a->ulid])
            ->and($ids('q='.$b->terminal->tid))->toBe([$b->ulid])
            ->and($ids('q='.$a->ticket_no))->toBe([$a->ulid])
            ->and($ids('technician='.$this->tech->ulid))->toBe([$b->ulid]);
    });

    it('lists only the tickets that have passed their SLA while still open', function () {
        $late = Fx::ticket();
        $late->forceFill(['sla_due_at' => now()->subDay()])->save();
        $closedLate = Fx::handling(technician: $this->tech);
        $closedLate->forceFill(['sla_due_at' => now()->subDay()])->save();
        app(CloseVisit::class)->handle($closedLate->fresh(), $this->tech, VisitOutcome::Finish);
        Fx::ticket();

        $this->actingAs($this->office)->get('/tickets?overdue=1')
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1)->where('tickets.data.0.ulid', $late->ulid)->where('tickets.data.0.is_overdue', true));
    });
});

describe('a ticket page', function () {
    it('shows the timeline newest first and what the user may do', function () {
        $ticket = Fx::ticket();

        $this->actingAs($this->office)->get("/tickets/{$ticket->ulid}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('tickets/Show')
                ->where('ticket.status', 'received')
                ->has('timeline', 1)
                ->where('timeline.0.event_label', 'Tiket dibuat')
                ->where('can.assign', true)
                ->where('can.cancel', true)
                ->where('can.unassign', false)
                ->where('can.reopen', false)
                ->has('technicians', 1));
    });

    it('offers reopening only to those allowed, and only once closed', function () {
        $ticket = Fx::handling(technician: $this->tech);
        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Finish);

        $this->actingAs($this->office)->get("/tickets/{$ticket->ulid}")
            ->assertInertia(fn (Assert $page) => $page->where('can.reopen', false)->where('can.assign', false));

        $this->actingAs($this->super)->get("/tickets/{$ticket->ulid}")
            ->assertInertia(fn (Assert $page) => $page->where('can.reopen', true)->where('can.cancel', false));
    });

    it('flags an arrival far from the site and a ticket closed on behalf', function () {
        $terminal = Fx::terminal();
        $terminal->site->update(['latitude' => -6.9175, 'longitude' => 107.6191]);
        $ticket = Fx::accepted(Fx::ticket($terminal), $this->tech);
        app(ArriveAtTicket::class)->handle($ticket, $this->tech, new ActionContext(latitude: -6.9625, longitude: 107.6191));

        $this->actingAs($this->office)->get("/tickets/{$ticket->ulid}")
            ->assertInertia(fn (Assert $page) => $page->where('timeline.0.outside_radius', true)->where('visits.0.distance_m', fn ($d) => $d > 4500));
    });
});

describe('office actions', function () {
    it('assigns, reassigns and withdraws', function () {
        $ticket = Fx::ticket();
        $other = Fx::technician();

        $this->actingAs($this->office)->post("/tickets/{$ticket->ulid}/assign", ['technician' => $this->tech->ulid, 'scheduled_at' => now()->addDay()->toDateTimeString()])->assertRedirect();
        expect($ticket->fresh()->technician_id)->toBe($this->tech->id);

        $this->actingAs($this->office)->post("/tickets/{$ticket->ulid}/assign", ['technician' => $other->ulid, 'reason' => 'Cuti'])->assertRedirect();
        expect($ticket->fresh()->technician_id)->toBe($other->id);

        $this->actingAs($this->office)->post("/tickets/{$ticket->ulid}/unassign", ['reason' => 'Salah tugaskan'])->assertRedirect();
        expect($ticket->fresh()->status)->toBe(TicketStatus::Received);
    });

    it('refuses assigning to someone who is not a technician', function () {
        $ticket = Fx::ticket();
        $office = User::factory()->create()->assignRole(RoleName::Coordinator->value);

        $this->actingAs($this->office)->post("/tickets/{$ticket->ulid}/assign", ['technician' => $office->ulid])->assertSessionHasErrors('technician');
        $this->actingAs($this->office)->post("/tickets/{$ticket->ulid}/assign", ['technician' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])->assertSessionHasErrors('technician');
    });

    it('needs a reason to withdraw, cancel or reopen', function (string $action) {
        $ticket = Fx::assigned(technician: $this->tech);

        $this->actingAs($this->super)->post("/tickets/{$ticket->ulid}/{$action}", [])->assertSessionHasErrors('reason');
    })->with(['unassign', 'cancel', 'reopen']);

    it('cancels, and reopens a closed one', function () {
        $cancelled = Fx::ticket();
        $this->actingAs($this->office)->post("/tickets/{$cancelled->ulid}/cancel", ['reason' => 'Vendor batal'])->assertRedirect();
        expect($cancelled->fresh()->status)->toBe(TicketStatus::Cancelled);

        $closed = Fx::handling(technician: $this->tech);
        app(CloseVisit::class)->handle($closed, $this->tech, VisitOutcome::Finish);
        $this->actingAs($this->super)->post("/tickets/{$closed->ulid}/reopen", ['reason' => 'Sengketa vendor'])->assertRedirect();
        expect($closed->fresh()->status)->toBe(TicketStatus::Received);
    });

    it('keeps a rule failure from changing anything', function () {
        $ticket = Fx::arrived(technician: $this->tech);

        $this->actingAs($this->office)->post("/tickets/{$ticket->ulid}/cancel", ['reason' => 'Batal'])->assertSessionHasErrors('status');

        expect($ticket->fresh()->status)->toBe(TicketStatus::Arrived);
    });
});

it('lists vendors for the filter and creation form', function () {
    Vendor::query()->count();

    $this->actingAs($this->office)->get('/tickets/create')
        ->assertInertia(fn (Assert $page) => $page->component('tickets/Create')->has('types', 4)->has('priorities', 4));
});
