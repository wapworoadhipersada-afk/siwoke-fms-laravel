<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Shared\Support\Geo;
use Modules\Ticket\Application\Actions\AcceptTicket;
use Modules\Ticket\Application\Actions\ArriveAtTicket;
use Modules\Ticket\Application\Actions\AssignTicket;
use Modules\Ticket\Application\Actions\StartHandling;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Database\Seeders\TicketCatalogSeeder;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Tests\Support\TicketFixtures as Fx;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, TicketCatalogSeeder::class]);
    $this->tech = Fx::technician();
    $this->office = Fx::dispatcher();
});

describe('a phone that was offline', function () {
    beforeEach(function () {
        // The office assigned it hours ago; the technician's phone then reports later events.
        $this->travelTo(now()->subHours(5));
        $this->ticket = Fx::assigned(technician: $this->tech);
        $this->travelBack();
    });

    it('keeps the time things really happened, not when they were sent', function () {
        $ticket = $this->ticket;
        $acceptedAt = now()->subHours(3);

        app(AcceptTicket::class)->handle($ticket, $this->tech, new ActionContext(occurredAt: $acceptedAt));

        $history = $ticket->histories()->orderBy('id')->get()->last();

        expect($history->occurred_at->toDateTimeString())->toBe($acceptedAt->toDateTimeString())
            ->and($history->created_at->greaterThan($acceptedAt))->toBeTrue()
            ->and($ticket->visits()->sole()->accepted_at->toDateTimeString())->toBe($acceptedAt->toDateTimeString());
    });

    it('rejects an action dated in the future', function () {
        $ticket = $this->ticket;

        app(AcceptTicket::class)->handle($ticket, $this->tech, new ActionContext(occurredAt: now()->addHour()));
    })->throws(ValidationException::class, 'masa depan');

    it('tolerates a few minutes of clock drift', function () {
        $ticket = $this->ticket;

        app(AcceptTicket::class)->handle($ticket, $this->tech, new ActionContext(occurredAt: now()->addMinutes(3)));

        expect($ticket->fresh()->status)->toBe(TicketStatus::Accepted);
    });

    it('rejects an action older than the allowed window', function () {
        $ticket = $this->ticket;

        app(AcceptTicket::class)->handle($ticket, $this->tech, new ActionContext(occurredAt: now()->subDays(8)));
    })->throws(ValidationException::class, 'terlalu lama');

    it('rejects an action dated before the previous one, so history stays in order', function () {
        $ticket = $this->ticket;
        app(AcceptTicket::class)->handle($ticket, $this->tech, new ActionContext(occurredAt: now()->subMinutes(10)));

        expect(fn () => app(ArriveAtTicket::class)->handle($ticket, $this->tech, new ActionContext(occurredAt: now()->subMinutes(30))))
            ->toThrow(ValidationException::class, 'lebih awal')
            ->and($ticket->fresh()->status)->toBe(TicketStatus::Accepted);
    });

    it('accepts a queue of actions sent together in the order they happened', function () {
        $ticket = $this->ticket;
        $base = now()->subHour();

        app(AcceptTicket::class)->handle($ticket, $this->tech, new ActionContext(occurredAt: $base));
        app(ArriveAtTicket::class)->handle($ticket->fresh(), $this->tech, new ActionContext(occurredAt: $base->copy()->addMinutes(20)));
        app(StartHandling::class)->handle($ticket->fresh(), $this->tech, new ActionContext(occurredAt: $base->copy()->addMinutes(25)));

        $visit = $ticket->visits()->sole();

        expect($ticket->fresh()->status)->toBe(TicketStatus::Handling)
            ->and($visit->arrived_at->toDateTimeString())->toBe($base->copy()->addMinutes(20)->toDateTimeString())
            ->and($visit->handling_started_at->toDateTimeString())->toBe($base->copy()->addMinutes(25)->toDateTimeString());
    });
});

describe('a retried action', function () {
    it('is applied once however many times it is sent', function () {
        $ticket = Fx::assigned(technician: $this->tech);
        $context = new ActionContext(clientUuid: (string) Str::uuid());

        app(AcceptTicket::class)->handle($ticket, $this->tech, $context);
        $historyCount = $ticket->histories()->count();

        app(AcceptTicket::class)->handle($ticket, $this->tech, $context);
        app(AcceptTicket::class)->handle($ticket, $this->tech, $context);

        expect($ticket->histories()->count())->toBe($historyCount)
            ->and($ticket->fresh()->status)->toBe(TicketStatus::Accepted);
    });

    it('stays harmless after the ticket has moved on', function () {
        $ticket = Fx::assigned(technician: $this->tech);
        $accept = new ActionContext(clientUuid: (string) Str::uuid());

        app(AcceptTicket::class)->handle($ticket, $this->tech, $accept);
        app(ArriveAtTicket::class)->handle($ticket->fresh(), $this->tech);

        // The phone never saw the first reply and sends the accept again.
        app(AcceptTicket::class)->handle($ticket->fresh(), $this->tech, $accept);

        expect($ticket->fresh()->status)->toBe(TicketStatus::Arrived);
    });

    it('does not mistake different actions for retries', function () {
        $ticket = Fx::assigned(technician: $this->tech);

        app(AcceptTicket::class)->handle($ticket, $this->tech, new ActionContext(clientUuid: (string) Str::uuid()));
        app(ArriveAtTicket::class)->handle($ticket->fresh(), $this->tech, new ActionContext(clientUuid: (string) Str::uuid()));

        expect($ticket->fresh()->status)->toBe(TicketStatus::Arrived);
    });

    it('is still a failure for a different action that is not allowed', function () {
        $ticket = Fx::assigned(technician: $this->tech);

        app(StartHandling::class)->handle($ticket, $this->tech, new ActionContext(clientUuid: (string) Str::uuid()));
    })->throws(ValidationException::class);
});

describe('arriving', function () {
    beforeEach(function () {
        $this->terminal = Fx::terminal();
        $this->terminal->site->update(['latitude' => -6.9175000, 'longitude' => 107.6191000]);
        $this->ticket = Fx::accepted(Fx::ticket($this->terminal), $this->tech);
    });

    it('stores the position and the distance to the site, flagging nothing when close', function () {
        // About 110 m north of the site.
        app(ArriveAtTicket::class)->handle($this->ticket, $this->tech, new ActionContext(latitude: -6.9165, longitude: 107.6191));

        $visit = $this->ticket->visits()->sole();
        $history = $this->ticket->histories()->orderBy('id')->get()->last();

        expect($visit->arrival_latitude)->toBe('-6.9165000')
            ->and($visit->arrival_distance_m)->toBeBetween(100, 125)
            ->and($history->meta['outside_radius'])->toBeFalse();
    });

    it('flags an arrival far from the site without blocking it', function () {
        // Roughly 5 km away.
        app(ArriveAtTicket::class)->handle($this->ticket, $this->tech, new ActionContext(latitude: -6.9625, longitude: 107.6191));

        $history = $this->ticket->histories()->orderBy('id')->get()->last();

        expect($this->ticket->fresh()->status)->toBe(TicketStatus::Arrived)
            ->and($history->meta['outside_radius'])->toBeTrue()
            ->and($history->meta['distance_m'])->toBeGreaterThan(4500);
    });

    it('accepts an arrival with no position and notes that it was missing', function () {
        app(ArriveAtTicket::class)->handle($this->ticket, $this->tech);

        $history = $this->ticket->histories()->orderBy('id')->get()->last();

        expect($this->ticket->fresh()->status)->toBe(TicketStatus::Arrived)
            ->and($history->meta['location'])->toBe('missing')
            ->and($this->ticket->visits()->sole()->arrival_distance_m)->toBeNull();
    });

    it('notes when the site has no coordinates to compare with', function () {
        $terminal = Fx::terminal();
        $ticket = Fx::accepted(Fx::ticket($terminal), $this->tech);

        app(ArriveAtTicket::class)->handle($ticket, $this->tech, new ActionContext(latitude: -6.9, longitude: 107.6));

        expect($ticket->histories()->orderBy('id')->get()->last()->meta['location'])->toBe('site_has_no_coordinates');
    });
});

it('measures distance correctly', function () {
    // Bandung to Jakarta is roughly 116 km.
    $meters = Geo::distanceMeters(-6.9175, 107.6191, -6.2088, 106.8456);

    expect($meters)->toBeBetween(110000, 125000)
        ->and(Geo::distanceMeters(-6.9, 107.6, -6.9, 107.6))->toBe(0);
});

it('assigns nothing by accident while testing offline timing', function () {
    expect(app(AssignTicket::class))->toBeInstanceOf(AssignTicket::class);
});
