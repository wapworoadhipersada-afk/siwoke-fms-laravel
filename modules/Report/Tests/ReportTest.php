<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Master\Tests\Support\MasterFixtures;
use Modules\Report\Application\Queries\TicketStatistics;
use Modules\Report\Application\Services\CsvCell;
use Modules\Ticket\Application\Actions\CloseVisit;
use Modules\Ticket\Database\Seeders\TicketCatalogSeeder;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Tests\Support\TicketFixtures as Fx;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, TicketCatalogSeeder::class]);
    $this->admin = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);
});

function finish(Ticket $ticket, ?User $technician = null): Ticket
{
    $technician ??= Fx::technician();
    $ticket = Fx::handling($ticket, $technician);
    app(CloseVisit::class)->handle($ticket, $technician, VisitOutcome::Finish);

    return $ticket->fresh();
}

describe('dashboard', function () {
    it('opens for someone with no operational access, showing nothing they should not see', function () {
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard')->where('tickets', null)->where('assets', null)->where('integration', null));
    });

    it('counts active, overdue and today\'s tickets', function () {
        $late = Fx::ticket();
        $late->forceFill(['sla_due_at' => now()->subHour()])->save();
        $pendingLate = Fx::ticket();
        $pendingLate->forceFill(['status' => TicketStatus::Pending, 'sla_due_at' => now()->subHour()])->save();
        Fx::ticket();
        finish(Fx::ticket());

        $stats = app(TicketStatistics::class)->handle($this->admin);

        // Pending tickets wait on someone else, so they are not counted as late.
        expect($stats['open'])->toBe(3)
            ->and($stats['overdue'])->toBe(1)
            ->and($stats['created_today'])->toBe(4)
            ->and($stats['closed_today'])->toBe(1)
            ->and($stats['by_status']['finished'])->toBe(1)
            ->and($stats['sla_percent'])->toBe(100.0);
    });

    it('measures SLA only over tickets that were closed', function () {
        $onTime = finish(Fx::ticket());
        $lateOne = finish(Fx::ticket());
        $lateOne->forceFill(['sla_due_at' => now()->subDay()])->save();

        $stats = app(TicketStatistics::class)->handle($this->admin);

        expect($stats['sla_percent'])->toBe(50.0)->and($onTime->exists)->toBeTrue();
    });

    it('gives a trend with one entry for each day, zeros included', function () {
        Fx::ticket();

        $trend = app(TicketStatistics::class)->handle($this->admin)['trend'];

        expect($trend)->toHaveCount(14)
            ->and(collect($trend)->sum('created'))->toBe(1)
            ->and(collect($trend)->last()['created'])->toBe(1);
    });

    it('shows each person only the tickets of their own area', function () {
        $mine = MasterFixtures::chain()['area'];
        $theirs = MasterFixtures::chain()['area'];
        Fx::ticket(Fx::terminal('MINE', $mine));
        Fx::ticket(Fx::terminal('THEIRS', $theirs));
        $scoped = Fx::scopedDispatcher([$mine]);

        expect(app(TicketStatistics::class)->handle($scoped)['open'])->toBe(1)
            ->and(app(TicketStatistics::class)->handle($this->admin)['open'])->toBe(2);
    });

    it('lists the busiest technicians for people who assign work', function () {
        $technician = Fx::technician();
        Fx::assigned(null, $technician);
        Fx::assigned(null, $technician);

        $workload = app(TicketStatistics::class)->handle($this->admin, withWorkload: true)['workload'];

        expect($workload)->toHaveCount(1)->and($workload[0]['active'])->toBe(2)->and($workload[0]['technician'])->toBe($technician->name);
    });
});

describe('ticket report', function () {
    it('lists tickets and filters them', function () {
        Fx::ticket(vendorTicketNo: 'FIND-ME-1');
        Fx::ticket(vendorTicketNo: 'OTHER-2');

        $this->actingAs($this->admin)->get(route('reports.tickets', ['q' => 'find-me']))->assertOk()
            ->assertInertia(fn ($page) => $page->component('reports/Tickets')->has('tickets.data', 1)->where('tickets.data.0.cells.1', 'FIND-ME-1'));

        $this->actingAs($this->admin)->get(route('reports.tickets', ['status' => 'finished']))->assertOk()
            ->assertInertia(fn ($page) => $page->has('tickets.data', 0));
    });

    it('filters by date range in the reporting timezone', function () {
        Fx::ticket();

        $this->actingAs($this->admin)->get(route('reports.tickets', ['from' => now()->addDay()->toDateString()]))->assertOk()
            ->assertInertia(fn ($page) => $page->has('tickets.data', 0));
        $this->actingAs($this->admin)->get(route('reports.tickets', ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]))->assertOk()
            ->assertInertia(fn ($page) => $page->has('tickets.data', 1));
    });

    it('treats a search for percent signs as text, not as a wildcard', function () {
        Fx::ticket();

        $this->actingAs($this->admin)->get(route('reports.tickets', ['q' => '%']))->assertOk()
            ->assertInertia(fn ($page) => $page->has('tickets.data', 0));
    });

    it('never shows tickets outside the viewer\'s area', function () {
        $mine = MasterFixtures::chain()['area'];
        Fx::ticket(Fx::terminal('MINE', $mine));
        Fx::ticket(Fx::terminal('THEIRS', MasterFixtures::chain()['area']));

        $this->actingAs(Fx::scopedDispatcher([$mine]))->get(route('reports.tickets'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('tickets.data', 1));
    });

    it('is closed to people without report access', function () {
        $technician = Fx::technician();

        $this->actingAs($technician)->get(route('reports.tickets'))->assertForbidden();
    });
});

describe('csv export', function () {
    it('downloads what is on screen as a spreadsheet-friendly file', function () {
        Fx::ticket(vendorTicketNo: 'EXP-1');
        Fx::ticket(vendorTicketNo: 'EXP-2');

        $response = $this->actingAs($this->admin)->get(route('reports.tickets.export'));
        $body = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", $body)));

        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue()
            ->and($lines)->toHaveCount(3)
            ->and($lines[0])->toContain('No. tiket')
            ->and($body)->toContain('EXP-1')->toContain('EXP-2');
    });

    it('keeps a hostile cell from running as a formula', function () {
        $ticket = Fx::ticket();
        $ticket->site->merchant->forceFill(['name' => '=HYPERLINK("http://evil.example","klik")'])->save();

        $body = $this->actingAs($this->admin)->get(route('reports.tickets.export'))->streamedContent();

        expect($body)->toContain("'=HYPERLINK")->not->toContain(',=HYPERLINK')->not->toContain('"=HYPERLINK');
    });

    it('stops at the configured ceiling', function () {
        config(['reports.max_export_rows' => 2]);
        Fx::ticket();
        Fx::ticket();
        Fx::ticket();

        $lines = array_values(array_filter(explode("\n", $this->actingAs($this->admin)->get(route('reports.tickets.export'))->streamedContent())));

        expect($lines)->toHaveCount(3);
    });

    it('applies the same filters as the screen', function () {
        Fx::ticket(vendorTicketNo: 'KEEP-1');
        Fx::ticket(vendorTicketNo: 'DROP-2');

        $body = $this->actingAs($this->admin)->get(route('reports.tickets.export', ['q' => 'KEEP']))->streamedContent();

        expect($body)->toContain('KEEP-1')->not->toContain('DROP-2');
    });

    it('is for people allowed to export, and the export is recorded', function () {
        $coordinator = User::factory()->create()->assignRole(RoleName::Coordinator->value);
        $this->actingAs($coordinator)->get(route('reports.tickets.export'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('reports.tickets.export'))->streamedContent();

        expect(DB::table('activity_log')->where('log_name', 'report')->where('event', 'ticket_export')->count())->toBe(1);
    });
});

it('neutralises every character that starts a spreadsheet formula', function (string $value) {
    expect(CsvCell::safe($value))->toBe("'".$value);
})->with(['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx"]);

it('leaves ordinary text alone', function () {
    expect(CsvCell::safe('Toko Maju'))->toBe('Toko Maju')->and(CsvCell::safe(''))->toBe('');
});
