<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Enums\UserType;
use Modules\Ticket\Application\Actions\AcceptTicket;
use Modules\Ticket\Application\Actions\ArriveAtTicket;
use Modules\Ticket\Application\Actions\AssignTicket;
use Modules\Ticket\Application\Actions\CancelTicket;
use Modules\Ticket\Application\Actions\CloseVisit;
use Modules\Ticket\Application\Actions\DepartForTicket;
use Modules\Ticket\Application\Actions\RejectTicket;
use Modules\Ticket\Application\Actions\ReopenTicket;
use Modules\Ticket\Application\Actions\StartHandling;
use Modules\Ticket\Application\Actions\UnassignTicket;
use Modules\Ticket\Application\Contracts\ClosureGuard;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Database\Seeders\TicketCatalogSeeder;
use Modules\Ticket\Domain\Enums\ReasonType;
use Modules\Ticket\Domain\Enums\TicketEvent;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketVisit;
use Modules\Ticket\Tests\Support\TicketFixtures as Fx;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, TicketCatalogSeeder::class]);
    $this->tech = Fx::technician();
    $this->office = Fx::dispatcher();
});

function eventsOf(Ticket $ticket): array
{
    return $ticket->histories()->orderBy('id')->get()->map(fn ($history) => $history->event)->all();
}

describe('the happy path', function () {
    it('takes a ticket from received to finished', function () {
        $ticket = Fx::ticket(creator: $this->office);

        app(AssignTicket::class)->handle($ticket, $this->tech, $this->office);
        app(AcceptTicket::class)->handle($ticket, $this->tech);
        app(DepartForTicket::class)->handle($ticket, $this->tech);
        app(ArriveAtTicket::class)->handle($ticket, $this->tech);
        app(StartHandling::class)->handle($ticket, $this->tech);
        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Finish);

        $ticket = $ticket->fresh();
        $visit = $ticket->visits()->sole();

        expect($ticket->status)->toBe(TicketStatus::Finished)
            ->and($ticket->final_result)->toBe('finish')
            ->and($ticket->closed_at)->not->toBeNull()
            ->and($ticket->technician_id)->toBeNull()
            ->and($visit->outcome)->toBe(VisitOutcome::Finish)
            ->and($visit->technician_id)->toBe($this->tech->id)
            ->and($visit->accepted_at)->not->toBeNull()
            ->and($visit->departed_at)->not->toBeNull()
            ->and($visit->arrived_at)->not->toBeNull()
            ->and($visit->handling_started_at)->not->toBeNull()
            ->and($visit->closed_at)->not->toBeNull()
            ->and(eventsOf($ticket))->toBe([
                TicketEvent::Created, TicketEvent::Assigned, TicketEvent::Accepted, TicketEvent::Departed,
                TicketEvent::Arrived, TicketEvent::HandlingStarted, TicketEvent::Finished,
            ]);
    });

    it('chains each history row to the status before it', function () {
        $ticket = Fx::handling();

        $pairs = $ticket->histories()->orderBy('id')->get()->map(fn ($h) => [$h->from_status?->value, $h->to_status->value])->all();

        expect($pairs)->toBe([
            [null, 'received'], ['received', 'assigned'], ['assigned', 'accepted'],
            ['accepted', 'arrived'], ['arrived', 'handling'],
        ]);
    });

    it('lets a technician skip the optional departure', function () {
        $ticket = Fx::arrived();

        expect($ticket->status)->toBe(TicketStatus::Arrived)
            ->and($ticket->visits()->sole()->departed_at)->toBeNull();
    });

    it('records who assigned it and to whom', function () {
        $ticket = Fx::ticket();

        app(AssignTicket::class)->handle($ticket, $this->tech, $this->office, now()->addDay());

        $assignment = $ticket->assignments()->sole();

        expect($assignment->technician_id)->toBe($this->tech->id)
            ->and($assignment->assigned_by)->toBe($this->office->id)
            ->and($assignment->unassigned_at)->toBeNull()
            ->and($ticket->visits()->sole()->scheduled_at)->not->toBeNull()
            ->and($ticket->fresh()->technician_id)->toBe($this->tech->id);
    });
});

describe('closing a visit', function () {
    it('cannot finish before the work has started', function () {
        $ticket = Fx::arrived(technician: $this->tech);

        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Finish);
    })->throws(ValidationException::class, 'tidak dapat ditutup');

    it('ends in pending with a pending reason and frees the technician', function () {
        $ticket = Fx::handling(technician: $this->tech);
        $reason = Fx::reason(ReasonType::Pending, 'PART_UNAVAILABLE');

        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Pending, reason: $reason, notes: 'Menunggu EDC dari vendor');

        $ticket = $ticket->fresh();
        $visit = $ticket->visits()->sole();

        expect($ticket->status)->toBe(TicketStatus::Pending)
            ->and($ticket->closed_at)->toBeNull()
            ->and($ticket->final_result)->toBeNull()
            ->and($ticket->technician_id)->toBeNull()
            ->and($visit->outcome)->toBe(VisitOutcome::Pending)
            ->and($visit->reason_code_id)->toBe($reason->id)
            ->and($visit->notes)->toBe('Menunggu EDC dari vendor');
    });

    it('can end in done negative straight after arriving, with a negative reason and notes', function () {
        $ticket = Fx::arrived(technician: $this->tech);

        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::DoneNegative, reason: Fx::reason(ReasonType::Negative, 'MERCHANT_CLOSED_PERMANENTLY'), notes: 'Ruko kosong, plang dicopot');

        $ticket = $ticket->fresh();

        expect($ticket->status)->toBe(TicketStatus::DoneNegative)
            ->and($ticket->final_result)->toBe('done_negative')
            ->and($ticket->closed_at)->not->toBeNull();
    });

    it('requires a pending reason of the right type', function (?string $type, ?string $code) {
        $ticket = Fx::handling(technician: $this->tech);
        $reason = $type === null ? null : Fx::reason(ReasonType::from($type), $code);

        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Pending, reason: $reason);
    })->with([
        'none' => [null, null],
        'a negative reason' => ['negative', 'ADDRESS_NOT_FOUND'],
    ])->throws(ValidationException::class, 'alasan pending');

    it('requires a negative reason of the right type and notes', function (string $type, string $code, ?string $notes, string $message) {
        $ticket = Fx::handling(technician: $this->tech);

        expect(fn () => app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::DoneNegative, reason: Fx::reason(ReasonType::from($type), $code), notes: $notes))
            ->toThrow(ValidationException::class, $message)
            ->and($ticket->fresh()->status)->toBe(TicketStatus::Handling);
    })->with([
        'pending reason' => ['pending', 'NETWORK_ISSUE', 'Catatan', 'alasan done negative'],
        'no notes' => ['negative', 'ADDRESS_NOT_FOUND', null, 'Catatan wajib'],
        'blank notes' => ['negative', 'ADDRESS_NOT_FOUND', '   ', 'Catatan wajib'],
    ]);

    it('refuses an inactive reason', function () {
        $ticket = Fx::handling(technician: $this->tech);
        $reason = Fx::reason(ReasonType::Pending, 'NETWORK_ISSUE');
        $reason->update(['is_active' => false]);

        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Pending, reason: $reason);
    })->throws(ValidationException::class);

    it('refuses to close a ticket that is not being worked', function (string $step) {
        $ticket = match ($step) {
            'received' => Fx::ticket(),
            'assigned' => Fx::assigned(technician: $this->tech),
        };

        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Finish);
    })->with(['received', 'assigned'])->throws(ValidationException::class);

    it('stores the checklist answers and where it was closed', function () {
        $ticket = Fx::handling(technician: $this->tech);

        app(CloseVisit::class)->handle(
            $ticket, $this->tech, VisitOutcome::Finish,
            new ActionContext(latitude: -6.9175, longitude: 107.6191),
            data: ['edc_serial' => 'SN-1', 'paper_rolls' => 2],
        );

        $visit = $ticket->visits()->sole();

        expect($visit->result_data)->toBe(['edc_serial' => 'SN-1', 'paper_rolls' => 2])
            ->and($visit->close_latitude)->toBe('-6.9175000');
    });

    it('lets a registered guard veto the closing, leaving everything untouched', function () {
        $ticket = Fx::handling(technician: $this->tech);

        $guard = new class implements ClosureGuard
        {
            public function assertCanClose(Ticket $ticket, TicketVisit $visit, VisitOutcome $outcome): void
            {
                throw ValidationException::withMessages(['photos' => 'Foto bukti belum diunggah.']);
            }
        };

        app()->instance('test.guard', $guard);
        app()->tag('test.guard', 'ticket.closure_guards');

        expect(fn () => app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Finish))->toThrow(ValidationException::class, 'Foto bukti')
            ->and($ticket->fresh()->status)->toBe(TicketStatus::Handling)
            ->and($ticket->visits()->sole()->outcome)->toBeNull();
    });
});

describe('pending and rescheduling', function () {
    beforeEach(function () {
        $this->ticket = Fx::handling(technician: $this->tech);
        app(CloseVisit::class)->handle($this->ticket, $this->tech, VisitOutcome::Pending, reason: Fx::reason(ReasonType::Pending, 'MERCHANT_NOT_READY'));
        $this->second = Fx::technician();
    });

    it('opens a second visit when the ticket is rescheduled and keeps the first one intact', function () {
        $first = $this->ticket->visits()->sole();
        $arrivedAt = $first->arrived_at;

        app(AssignTicket::class)->handle($this->ticket->fresh(), $this->second, $this->office);

        $visits = $this->ticket->visits()->orderBy('visit_no')->get();

        expect($visits)->toHaveCount(2)
            ->and($visits[0]->outcome)->toBe(VisitOutcome::Pending)
            ->and($visits[0]->technician_id)->toBe($this->tech->id)
            ->and($visits[0]->arrived_at->equalTo($arrivedAt))->toBeTrue()
            ->and($visits[1]->visit_no)->toBe(2)
            ->and($visits[1]->technician_id)->toBe($this->second->id)
            ->and($visits[1]->outcome)->toBeNull()
            ->and($visits[1]->arrived_at)->toBeNull()
            ->and($this->ticket->fresh()->status)->toBe(TicketStatus::Assigned)
            ->and(array_slice(eventsOf($this->ticket), -1)[0])->toBe(TicketEvent::Rescheduled);
    });

    it('can be finished on the second visit', function () {
        app(AssignTicket::class)->handle($this->ticket->fresh(), $this->second, $this->office);
        $ticket = $this->ticket->fresh();

        app(AcceptTicket::class)->handle($ticket, $this->second);
        app(ArriveAtTicket::class)->handle($ticket, $this->second);
        app(StartHandling::class)->handle($ticket, $this->second);
        app(CloseVisit::class)->handle($ticket, $this->second, VisitOutcome::Finish);

        expect($ticket->fresh()->status)->toBe(TicketStatus::Finished)
            ->and($ticket->visits()->where('outcome', 'finish')->count())->toBe(1)
            ->and($ticket->visits()->count())->toBe(2);
    });

    it('can go back to the same technician', function () {
        app(AssignTicket::class)->handle($this->ticket->fresh(), $this->tech, $this->office);

        expect($this->ticket->visits()->count())->toBe(2)
            ->and($this->ticket->fresh()->technician_id)->toBe($this->tech->id);
    });

    it('no longer shows the first technician as responsible', function () {
        expect($this->ticket->fresh()->technician_id)->toBeNull();
    });
});

describe('assigning and reassigning', function () {
    it('moves a not-yet-started job to another technician on the same visit', function () {
        $ticket = Fx::assigned(technician: $this->tech);
        $other = Fx::technician();

        app(AssignTicket::class)->handle($ticket->fresh(), $other, $this->office, reason: 'Teknisi pertama cuti');

        $assignments = $ticket->assignments()->orderBy('id')->get();

        expect($ticket->visits()->count())->toBe(1)
            ->and($ticket->visits()->sole()->technician_id)->toBe($other->id)
            ->and($ticket->fresh()->technician_id)->toBe($other->id)
            ->and($assignments)->toHaveCount(2)
            ->and($assignments[0]->unassigned_at)->not->toBeNull()
            ->and($assignments[0]->unassign_reason)->toBe('Teknisi pertama cuti')
            ->and($assignments[1]->unassigned_at)->toBeNull()
            ->and(eventsOf($ticket)[2])->toBe(TicketEvent::Reassigned);
    });

    it('makes the new technician accept again after a reassignment', function () {
        $ticket = Fx::accepted(technician: $this->tech);
        $other = Fx::technician();

        app(AssignTicket::class)->handle($ticket->fresh(), $other, $this->office);

        expect($ticket->fresh()->status)->toBe(TicketStatus::Assigned)
            ->and($ticket->visits()->sole()->accepted_at)->toBeNull();
    });

    it('will not move a job once the technician is on the way', function () {
        $ticket = Fx::accepted(technician: $this->tech);
        app(DepartForTicket::class)->handle($ticket, $this->tech);

        app(AssignTicket::class)->handle($ticket->fresh(), Fx::technician(), $this->office);
    })->throws(ValidationException::class, 'tidak dapat ditugaskan');

    it('refuses to assign to the technician who already has it', function () {
        $ticket = Fx::assigned(technician: $this->tech);

        app(AssignTicket::class)->handle($ticket->fresh(), $this->tech, $this->office);
    })->throws(ValidationException::class, 'sudah ditugaskan');

    it('refuses someone who cannot work tickets', function (string $who) {
        $person = match ($who) {
            'an inactive technician' => tap(Fx::technician(), fn (User $user) => $user->update(['is_active' => false])),
            'an office user' => User::factory()->create(['user_type' => UserType::Staff])->assignRole(RoleName::Coordinator->value),
        };

        app(AssignTicket::class)->handle(Fx::ticket(), $person, $this->office);
    })->with(['an inactive technician', 'an office user'])->throws(ValidationException::class, 'tidak berhak');

    it('refuses to assign a finished ticket', function () {
        $ticket = Fx::handling(technician: $this->tech);
        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Finish);

        app(AssignTicket::class)->handle($ticket->fresh(), Fx::technician(), $this->office);
    })->throws(ValidationException::class);
});

describe('giving a job back', function () {
    it('lets the technician reject it with a reason, returning it to the pool', function () {
        $ticket = Fx::assigned(technician: $this->tech);

        app(RejectTicket::class)->handle($ticket, $this->tech, 'Lokasi di luar area saya');

        $ticket = $ticket->fresh();
        $visit = $ticket->visits()->sole();

        expect($ticket->status)->toBe(TicketStatus::Received)
            ->and($ticket->technician_id)->toBeNull()
            ->and($visit->outcome)->toBe(VisitOutcome::Cancelled)
            ->and($ticket->assignments()->sole()->unassigned_at)->not->toBeNull()
            ->and(eventsOf($ticket))->toContain(TicketEvent::Rejected);
    });

    it('can then be assigned again on a fresh visit', function () {
        $ticket = Fx::assigned(technician: $this->tech);
        app(RejectTicket::class)->handle($ticket, $this->tech, 'Tidak sempat');

        app(AssignTicket::class)->handle($ticket->fresh(), Fx::technician(), $this->office);

        expect($ticket->visits()->count())->toBe(2)->and($ticket->fresh()->status)->toBe(TicketStatus::Assigned);
    });

    it('requires a reason', function () {
        app(RejectTicket::class)->handle(Fx::assigned(technician: $this->tech), $this->tech, '  ');
    })->throws(ValidationException::class, 'Alasan');

    it('cannot be done by a technician who is not the assignee', function () {
        app(RejectTicket::class)->handle(Fx::assigned(technician: $this->tech), Fx::technician(), 'Bukan tugas saya');
    })->throws(AuthorizationException::class);

    it('cannot be rejected after arriving', function () {
        app(RejectTicket::class)->handle(Fx::arrived(technician: $this->tech), $this->tech, 'Berubah pikiran');
    })->throws(ValidationException::class);

    it('lets the office withdraw a job before work starts', function () {
        $ticket = Fx::accepted(technician: $this->tech);

        app(UnassignTicket::class)->handle($ticket, $this->office, 'Salah tugaskan');

        expect($ticket->fresh()->status)->toBe(TicketStatus::Received)
            ->and(eventsOf($ticket))->toContain(TicketEvent::Unassigned);
    });
});

describe('cancelling', function () {
    it('cancels from any status before arrival, ending the open visit', function (string $step) {
        $ticket = match ($step) {
            'received' => Fx::ticket(),
            'assigned' => Fx::assigned(technician: $this->tech),
            'accepted' => Fx::accepted(technician: $this->tech),
        };

        app(CancelTicket::class)->handle($ticket, $this->office, 'Vendor membatalkan');

        $ticket = $ticket->fresh();

        expect($ticket->status)->toBe(TicketStatus::Cancelled)
            ->and($ticket->technician_id)->toBeNull()
            ->and($ticket->visits()->whereNull('outcome')->count())->toBe(0);
    })->with(['received', 'assigned', 'accepted']);

    it('cancels a pending ticket', function () {
        $ticket = Fx::handling(technician: $this->tech);
        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Pending, reason: Fx::reason(ReasonType::Pending, 'NETWORK_ISSUE'));

        app(CancelTicket::class)->handle($ticket->fresh(), $this->office, 'Vendor menarik permintaan');

        expect($ticket->fresh()->status)->toBe(TicketStatus::Cancelled);
    });

    it('cannot cancel once the technician has arrived, and keeps the visit', function () {
        $ticket = Fx::arrived(technician: $this->tech);

        expect(fn () => app(CancelTicket::class)->handle($ticket, $this->office, 'Batal'))->toThrow(ValidationException::class)
            ->and($ticket->visits()->sole()->outcome)->toBeNull()
            ->and($ticket->fresh()->status)->toBe(TicketStatus::Arrived);
    });

    it('requires a reason', function () {
        app(CancelTicket::class)->handle(Fx::ticket(), $this->office, ' ');
    })->throws(ValidationException::class, 'Alasan');

    it('is final', function () {
        $ticket = Fx::ticket();
        app(CancelTicket::class)->handle($ticket, $this->office, 'Batal');

        app(AssignTicket::class)->handle($ticket->fresh(), $this->tech, $this->office);
    })->throws(ValidationException::class);
});

describe('reopening', function () {
    it('brings a finished ticket back with a reason, keeping the original closure in the history', function () {
        $ticket = Fx::handling(technician: $this->tech);
        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Finish);

        app(ReopenTicket::class)->handle($ticket->fresh(), $this->office, 'Vendor: mesin masih bermasalah');

        $ticket = $ticket->fresh();
        $history = $ticket->histories()->orderBy('id')->get();

        expect($ticket->status)->toBe(TicketStatus::Received)
            ->and($ticket->closed_at)->toBeNull()
            ->and($ticket->final_result)->toBeNull()
            ->and($history->pluck('event')->contains(TicketEvent::Finished))->toBeTrue()
            ->and($history->last()->event)->toBe(TicketEvent::Reopened)
            ->and($history->last()->reason)->toBe('Vendor: mesin masih bermasalah')
            ->and($history->last()->meta['previous_result'])->toBe('finished');
    });

    it('gets a new visit when assigned again, leaving the old one alone', function () {
        $ticket = Fx::handling(technician: $this->tech);
        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Finish);
        app(ReopenTicket::class)->handle($ticket->fresh(), $this->office, 'Ulang');

        app(AssignTicket::class)->handle($ticket->fresh(), Fx::technician(), $this->office);

        expect($ticket->visits()->orderBy('visit_no')->get()->pluck('outcome')->all())->toBe([VisitOutcome::Finish, null]);
    });

    it('requires a reason', function () {
        $ticket = Fx::handling(technician: $this->tech);
        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Finish);

        app(ReopenTicket::class)->handle($ticket->fresh(), $this->office, '');
    })->throws(ValidationException::class, 'Alasan');

    it('only applies to finished or done negative tickets', function (string $status) {
        $ticket = $status === 'cancelled' ? tap(Fx::ticket(), fn ($t) => app(CancelTicket::class)->handle($t, $this->office, 'Batal')) : Fx::ticket();

        app(ReopenTicket::class)->handle($ticket->fresh(), $this->office, 'Coba');
    })->with(['received', 'cancelled'])->throws(ValidationException::class);
});

describe('who may act', function () {
    it('lets only the assigned technician work on the ticket', function (string $action) {
        $ticket = Fx::assigned(technician: $this->tech);
        $stranger = Fx::technician();

        $act = match ($action) {
            'accept' => fn () => app(AcceptTicket::class)->handle($ticket, $stranger),
            'arrive' => fn () => app(ArriveAtTicket::class)->handle($ticket, $stranger),
            'depart' => fn () => app(DepartForTicket::class)->handle($ticket, $stranger),
        };

        expect($act)->toThrow(AuthorizationException::class)
            ->and($ticket->fresh()->status)->toBe(TicketStatus::Assigned);
    })->with(['accept', 'arrive', 'depart']);

    it('lets a user with the override permission act for the technician, and says so in the history', function () {
        $ticket = Fx::assigned(technician: $this->tech);
        $override = User::factory()->create()->assignRole(RoleName::Leader->value)->givePermissionTo('ticket.close.override');

        app(AcceptTicket::class)->handle($ticket, $override, new ActionContext(onBehalf: true));

        $history = $ticket->histories()->orderBy('id')->get()->last();

        expect($ticket->fresh()->status)->toBe(TicketStatus::Accepted)
            ->and($history->actor_id)->toBe($override->id)
            ->and($history->meta['on_behalf'])->toBeTrue();
    });

    it('refuses acting on behalf without the permission, even when asked to', function () {
        $ticket = Fx::assigned(technician: $this->tech);

        app(AcceptTicket::class)->handle($ticket, $this->office, new ActionContext(onBehalf: true));
    })->throws(AuthorizationException::class);

    it('refuses acting on behalf when not asked to, even with the permission', function () {
        $ticket = Fx::assigned(technician: $this->tech);
        $override = User::factory()->create()->assignRole(RoleName::Leader->value)->givePermissionTo('ticket.close.override');

        app(AcceptTicket::class)->handle($ticket, $override);
    })->throws(AuthorizationException::class);
});
