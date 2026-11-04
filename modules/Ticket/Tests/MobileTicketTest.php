<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Ticket\Application\Actions\AssignTicket;
use Modules\Ticket\Application\Actions\CloseVisit;
use Modules\Ticket\Database\Seeders\TicketCatalogSeeder;
use Modules\Ticket\Domain\Enums\ReasonType;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\ReasonCode;
use Modules\Ticket\Tests\Support\TicketFixtures as Fx;

const TICKETS = '/api/mobile/v1/tickets';

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, TicketCatalogSeeder::class]);
    $this->tech = Fx::technician();
    $this->office = Fx::dispatcher();
});

function asTech(User $user): void
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($user, ['mobile']);
}

describe('my jobs', function () {
    it('lists what I am responsible for, with everything I need on site', function () {
        $terminal = Fx::terminal();
        $terminal->site->update(['latitude' => -6.9175, 'longitude' => 107.6191, 'pic_name' => 'Bu Ani', 'pic_phone' => '0812']);
        $ticket = Fx::assigned(Fx::ticket($terminal, 'INSTALL'), $this->tech);
        Fx::assigned(Fx::ticket(), Fx::technician());

        asTech($this->tech);

        $this->getJson(TICKETS)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ticket->ulid)
            ->assertJsonPath('data.0.ticket_no', $ticket->ticket_no)
            ->assertJsonPath('data.0.type.code', 'INSTALL')
            ->assertJsonPath('data.0.status', 'assigned')
            ->assertJsonPath('data.0.terminal.tid', $terminal->tid)
            ->assertJsonPath('data.0.site.pic_name', 'Bu Ani')
            ->assertJsonPath('data.0.site.latitude', '-6.9175000')
            ->assertJsonPath('data.0.actions', ['accept', 'reject']);
    });

    it('lists what I finished or paused under history', function () {
        $done = Fx::handling(technician: $this->tech);
        app(CloseVisit::class)->handle($done, $this->tech, VisitOutcome::Finish);
        $active = Fx::assigned(technician: $this->tech);

        asTech($this->tech);

        $this->getJson(TICKETS.'?scope=history')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $done->ulid)->assertJsonPath('data.0.actions', []);
        $this->getJson(TICKETS)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->ulid);
    });

    it('offers the next steps that fit each status', function (string $step, array $expected) {
        $ticket = match ($step) {
            'accepted' => Fx::accepted(technician: $this->tech),
            'arrived' => Fx::arrived(technician: $this->tech),
            'handling' => Fx::handling(technician: $this->tech),
        };

        asTech($this->tech);

        $this->getJson(TICKETS."/{$ticket->ulid}")->assertOk()->assertJsonPath('data.actions', $expected);
    })->with([
        'accepted' => ['accepted', ['depart', 'arrive', 'reject']],
        'arrived' => ['arrived', ['start', 'close_pending', 'close_negative']],
        'handling' => ['handling', ['close_finish', 'close_pending', 'close_negative']],
    ]);

    it('hides a ticket that is not mine as if it did not exist', function () {
        $ticket = Fx::assigned(technician: Fx::technician());

        asTech($this->tech);

        $this->getJson(TICKETS."/{$ticket->ulid}")->assertNotFound();
        $this->postJson(TICKETS."/{$ticket->ulid}/accept")->assertNotFound();
    });

    it('hides a ticket that was taken away from me before I started', function () {
        $ticket = Fx::assigned(technician: $this->tech);
        app(AssignTicket::class)->handle($ticket, Fx::technician(), $this->office);

        asTech($this->tech);

        $this->postJson(TICKETS."/{$ticket->ulid}/accept")->assertNotFound();
    });

    it('is closed to people who do not work tickets and to anonymous callers', function () {
        $this->getJson(TICKETS)->assertUnauthorized();

        asTech(User::factory()->create()->assignRole(RoleName::Coordinator->value));
        $this->getJson(TICKETS)->assertForbidden();
    });

    it('offers the reason codes to pick from', function () {
        asTech($this->tech);

        $response = $this->getJson('/api/mobile/v1/reason-codes')->assertOk();

        expect(collect($response->json('data'))->pluck('type')->unique()->sort()->values()->all())->toBe(['negative', 'pending'])
            ->and($response->json('data.0'))->toHaveKeys(['id', 'type', 'code', 'name']);
    });
});

describe('working a job from the phone', function () {
    it('takes a job from assigned to finished, recording where I was', function () {
        $terminal = Fx::terminal();
        $terminal->site->update(['latitude' => -6.9175, 'longitude' => 107.6191]);
        $ticket = Fx::assigned(Fx::ticket($terminal), $this->tech);

        asTech($this->tech);

        $this->postJson(TICKETS."/{$ticket->ulid}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->postJson(TICKETS."/{$ticket->ulid}/depart")->assertOk()->assertJsonPath('data.status', 'on_the_way')->assertJsonPath('data.actions', ['arrive']);
        $this->postJson(TICKETS."/{$ticket->ulid}/arrive", ['latitude' => -6.9176, 'longitude' => 107.6192, 'accuracy' => 12.5])
            ->assertOk()->assertJsonPath('data.status', 'arrived');
        $this->postJson(TICKETS."/{$ticket->ulid}/start")->assertOk()->assertJsonPath('data.status', 'handling');
        $this->postJson(TICKETS."/{$ticket->ulid}/close", ['result' => 'finish', 'data' => ['edc_serial' => 'SN-9']])
            ->assertOk()->assertJsonPath('data.status', 'finished')->assertJsonPath('data.actions', [])->assertJsonPath('data.visit.outcome', 'finish');

        $visit = $ticket->visits()->sole();

        expect($visit->arrival_latitude)->toBe('-6.9176000')
            ->and($visit->arrival_distance_m)->toBeLessThan(30)
            ->and($visit->result_data)->toBe(['edc_serial' => 'SN-9']);
    });

    it('records when it really happened and ignores a repeated send', function () {
        $this->travelTo(now()->subHours(3));
        $ticket = Fx::assigned(technician: $this->tech);
        $this->travelBack();
        $uuid = (string) Str::uuid();
        $at = now()->subHour()->toIso8601String();

        asTech($this->tech);

        $this->postJson(TICKETS."/{$ticket->ulid}/accept", ['client_uuid' => $uuid, 'occurred_at' => $at])->assertOk();
        $this->postJson(TICKETS."/{$ticket->ulid}/accept", ['client_uuid' => $uuid, 'occurred_at' => $at])->assertOk()->assertJsonPath('data.status', 'accepted');

        $history = $ticket->histories()->where('client_uuid', $uuid)->get();

        expect($history)->toHaveCount(1)
            ->and($history->first()->occurred_at->toDateTimeString())->toBe(Carbon::parse($at)->toDateTimeString());
    });

    it('rejects a time in the future', function () {
        $ticket = Fx::assigned(technician: $this->tech);

        asTech($this->tech);

        $this->postJson(TICKETS."/{$ticket->ulid}/accept", ['occurred_at' => now()->addDay()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
    });

    it('answers a step that is not allowed with a clear validation error', function () {
        $ticket = Fx::assigned(technician: $this->tech);

        asTech($this->tech);

        $this->postJson(TICKETS."/{$ticket->ulid}/start")->assertUnprocessable()->assertJsonValidationErrors('status');
        expect($ticket->fresh()->status)->toBe(TicketStatus::Assigned);
    });

    it('needs a reason to reject a job, then returns it to the office', function () {
        $ticket = Fx::assigned(technician: $this->tech);

        asTech($this->tech);

        $this->postJson(TICKETS."/{$ticket->ulid}/reject", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(TICKETS."/{$ticket->ulid}/reject", ['reason' => 'Saya cuti'])->assertOk()->assertJsonPath('data.status', 'received');

        $this->getJson(TICKETS)->assertJsonCount(0, 'data');
    });

    it('requires a coordinate pair, not half of one', function () {
        $ticket = Fx::accepted(technician: $this->tech);

        asTech($this->tech);

        $this->postJson(TICKETS."/{$ticket->ulid}/arrive", ['latitude' => -6.9])->assertUnprocessable()->assertJsonValidationErrors('longitude');
    });
});

describe('closing', function () {
    beforeEach(function () {
        $this->ticket = Fx::handling(technician: $this->tech);
        asTech($this->tech);
    });

    it('needs a result', function () {
        $this->postJson(TICKETS."/{$this->ticket->ulid}/close", [])->assertUnprocessable()->assertJsonValidationErrors('result');
    });

    it('refuses a result that is not one of the three', function (string $result) {
        $this->postJson(TICKETS."/{$this->ticket->ulid}/close", ['result' => $result])->assertUnprocessable()->assertJsonValidationErrors('result');
    })->with(['cancelled', 'done', 'FINISH']);

    it('pends with a pending reason', function () {
        $reason = Fx::reason(ReasonType::Pending, 'PART_UNAVAILABLE');

        $this->postJson(TICKETS."/{$this->ticket->ulid}/close", ['result' => 'pending', 'reason_code' => $reason->ulid, 'notes' => 'Tunggu mesin'])
            ->assertOk()->assertJsonPath('data.status', 'pending');
    });

    it('needs a reason code to pend or close negative', function (string $result) {
        $this->postJson(TICKETS."/{$this->ticket->ulid}/close", ['result' => $result])->assertUnprocessable()->assertJsonValidationErrors('reason_code');
    })->with(['pending', 'done_negative']);

    it('refuses a reason code that does not exist or has the wrong type', function () {
        $this->postJson(TICKETS."/{$this->ticket->ulid}/close", ['result' => 'pending', 'reason_code' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason_code');

        $negative = ReasonCode::query()->where('type', 'negative')->first();

        $this->postJson(TICKETS."/{$this->ticket->ulid}/close", ['result' => 'pending', 'reason_code' => $negative->ulid])
            ->assertUnprocessable()->assertJsonValidationErrors('reason_code');
    });

    it('closes negative with a negative reason and notes', function () {
        $reason = Fx::reason(ReasonType::Negative, 'ADDRESS_NOT_FOUND');

        $this->postJson(TICKETS."/{$this->ticket->ulid}/close", ['result' => 'done_negative', 'reason_code' => $reason->ulid])
            ->assertUnprocessable()->assertJsonValidationErrors('notes');

        $this->postJson(TICKETS."/{$this->ticket->ulid}/close", ['result' => 'done_negative', 'reason_code' => $reason->ulid, 'notes' => 'Alamat tidak ada'])
            ->assertOk()->assertJsonPath('data.status', 'done_negative');
    });
});
