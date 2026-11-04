<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Master\Domain\Enums\TerminalStatus;
use Modules\Ticket\Application\Actions\CreateTicket;
use Modules\Ticket\Database\Seeders\TicketCatalogSeeder;
use Modules\Ticket\Domain\Enums\TicketEvent;
use Modules\Ticket\Domain\Enums\TicketPriority;
use Modules\Ticket\Domain\Enums\TicketSource;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\ReasonCode;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketStatusHistory;
use Modules\Ticket\Domain\Models\TicketType;
use Modules\Ticket\Tests\Support\TicketFixtures as Fx;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, TicketCatalogSeeder::class]);
});

describe('creating a ticket', function () {
    it('takes vendor and site from the terminal and computes the SLA', function () {
        $terminal = Fx::terminal();
        $type = Fx::type('INSTALL');

        $ticket = app(CreateTicket::class)->handle($terminal, $type, Fx::dispatcher(), TicketPriority::High, description: 'Pasang mesin baru');

        expect($ticket->vendor_id)->toBe($terminal->vendor_id)
            ->and($ticket->merchant_site_id)->toBe($terminal->merchant_site_id)
            ->and($ticket->terminal_id)->toBe($terminal->id)
            ->and($ticket->status)->toBe(TicketStatus::Received)
            ->and($ticket->priority)->toBe(TicketPriority::High)
            ->and($ticket->source)->toBe(TicketSource::Manual)
            ->and($ticket->ticket_no)->toMatch('/^TK-\d{8}-\d{6}$/')
            ->and($ticket->sla_due_at->equalTo($ticket->received_at->copy()->addHours(72)))->toBeTrue()
            ->and($ticket->technician_id)->toBeNull();
    });

    it('gives every ticket its own number, in order', function () {
        $first = Fx::ticket();
        $second = Fx::ticket();

        expect($second->ticket_no)->not->toBe($first->ticket_no)
            ->and($second->ticket_no > $first->ticket_no)->toBeTrue();
    });

    it('records the creation as the first history entry', function () {
        $creator = Fx::dispatcher();

        $ticket = Fx::ticket(creator: $creator);
        $history = $ticket->histories()->sole();

        expect($history->event)->toBe(TicketEvent::Created)
            ->and($history->from_status)->toBeNull()
            ->and($history->to_status)->toBe(TicketStatus::Received)
            ->and($history->actor_id)->toBe($creator->id);
    });

    it('keeps the vendor original request as received', function () {
        $payload = ['ticket_id' => 'BRI-998', 'sla' => 'P2', 'nested' => ['a' => 1]];

        $ticket = app(CreateTicket::class)->handle(Fx::terminal(), Fx::type(), null, source: TicketSource::Api, payload: $payload);

        // jsonb does not preserve key order, so compare content, not order.
        expect($ticket->fresh()->payload)->toEqual($payload)
            ->and($ticket->source)->toBe(TicketSource::Api)
            ->and($ticket->created_by)->toBeNull()
            ->and($ticket->histories()->sole()->actor_type)->toBe('system');
    });

    it('refuses a terminal that was withdrawn', function () {
        $terminal = Fx::terminal();
        $terminal->update(['status' => TerminalStatus::Removed]);

        Fx::ticket($terminal);
    })->throws(ValidationException::class, 'sudah ditarik');

    it('refuses an inactive ticket type', function () {
        $type = Fx::type();
        $type->update(['is_active' => false]);

        app(CreateTicket::class)->handle(Fx::terminal(), $type, Fx::dispatcher());
    })->throws(ValidationException::class, 'tidak aktif');

    it('refuses a terminal of an inactive vendor', function () {
        $terminal = Fx::terminal();
        $terminal->vendor->update(['is_active' => false]);

        Fx::ticket($terminal);
    })->throws(ValidationException::class, 'Vendor');
});

describe('the vendor reference', function () {
    it('is unique per vendor', function () {
        $terminal = Fx::terminal();
        Fx::ticket($terminal, vendorTicketNo: 'BRI-001');

        Fx::ticket($terminal, vendorTicketNo: 'bri-001');
    })->throws(ValidationException::class, 'sudah terdaftar');

    it('may repeat across different vendors', function () {
        Fx::ticket(Fx::terminal('A'), vendorTicketNo: 'REF-1');
        Fx::ticket(Fx::terminal('B'), vendorTicketNo: 'REF-1');

        expect(Ticket::query()->where('vendor_ticket_no', 'REF-1')->count())->toBe(2);
    });

    it('may be absent on many tickets', function () {
        $terminal = Fx::terminal();
        Fx::ticket($terminal);
        Fx::ticket($terminal);

        expect(Ticket::query()->whereNull('vendor_ticket_no')->count())->toBe(2);
    });

    it('is freed when the ticket is deleted', function () {
        $terminal = Fx::terminal();
        Fx::ticket($terminal, vendorTicketNo: 'REF-9')->delete();

        expect(Fx::ticket($terminal, vendorTicketNo: 'REF-9')->exists)->toBeTrue();
    });
});

describe('the database guards the shape of a ticket', function () {
    it('refuses a closed ticket without a result', function () {
        $ticket = Fx::ticket();

        DB::table('tickets')->where('id', $ticket->id)->update(['status' => 'finished']);
    })->throws(QueryException::class);

    it('refuses an open ticket that claims a result', function () {
        $ticket = Fx::ticket();

        DB::table('tickets')->where('id', $ticket->id)->update(['final_result' => 'finish']);
    })->throws(QueryException::class);

    it('refuses an assigned ticket with nobody on it', function () {
        $ticket = Fx::ticket();

        DB::table('tickets')->where('id', $ticket->id)->update(['status' => 'handling']);
    })->throws(QueryException::class);

    it('refuses an unknown status or priority', function (string $column, string $value) {
        $ticket = Fx::ticket();

        DB::table('tickets')->where('id', $ticket->id)->update([$column => $value]);
    })->with([['status', 'teleported'], ['priority', 'whenever']])->throws(QueryException::class);

    it('refuses two open visits for one ticket', function () {
        $ticket = Fx::assigned();
        $visit = $ticket->visits()->sole();

        DB::table('ticket_visits')->insert([
            'ticket_id' => $ticket->id, 'visit_no' => 2, 'technician_id' => $visit->technician_id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    })->throws(QueryException::class);
});

describe('the history cannot be rewritten', function () {
    it('refuses an update', function () {
        Fx::ticket();

        DB::table('ticket_status_histories')->update(['reason' => 'rewritten']);
    })->throws(QueryException::class, 'append-only');

    it('refuses a delete', function () {
        Fx::ticket();

        TicketStatusHistory::query()->delete();
    })->throws(QueryException::class, 'append-only');

    it('refuses a truncate', function () {
        DB::statement('TRUNCATE ticket_status_histories');
    })->throws(QueryException::class, 'append-only');

    it('refuses the same client id twice', function () {
        $ticket = Fx::ticket();
        $row = ['ticket_id' => $ticket->id, 'event' => 'created', 'to_status' => 'received', 'client_uuid' => (string) Str::uuid(), 'occurred_at' => now(), 'meta' => '{}'];

        DB::table('ticket_status_histories')->insert($row);
        DB::table('ticket_status_histories')->insert($row);
    })->throws(QueryException::class);
});

it('seeds the four ticket types and the reason codes, and can be re-run', function () {
    $this->seed(TicketCatalogSeeder::class);

    expect(TicketType::query()->pluck('code')->all())->toEqualCanonicalizing(['INSTALL', 'MAINTENANCE', 'PULLOUT', 'REPLACE'])
        ->and(ReasonCode::query()->where('type', 'pending')->count())->toBeGreaterThan(2)
        ->and(ReasonCode::query()->where('type', 'negative')->count())->toBeGreaterThan(2);
});

it('knows when a ticket has missed its SLA', function () {
    $ticket = Fx::ticket();

    expect($ticket->isOverdue())->toBeFalse();

    $ticket->forceFill(['sla_due_at' => now()->subHour()])->save();

    expect($ticket->isOverdue())->toBeTrue();
});
