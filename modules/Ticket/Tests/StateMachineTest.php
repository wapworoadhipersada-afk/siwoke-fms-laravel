<?php

declare(strict_types=1);

use Modules\Ticket\Domain\Enums\TicketStatus as S;

it('allows exactly the transitions of the documented state machine', function (S $from, array $allowed) {
    expect($from->allowedNext())->toEqualCanonicalizing($allowed);
})->with([
    'received' => [S::Received, [S::Assigned, S::Cancelled]],
    'assigned' => [S::Assigned, [S::Assigned, S::Accepted, S::Received, S::Cancelled]],
    'accepted' => [S::Accepted, [S::Assigned, S::OnTheWay, S::Arrived, S::Received, S::Cancelled]],
    'on the way' => [S::OnTheWay, [S::Arrived, S::Cancelled]],
    'arrived' => [S::Arrived, [S::Handling, S::Pending, S::DoneNegative]],
    'handling' => [S::Handling, [S::Finished, S::Pending, S::DoneNegative]],
    'pending' => [S::Pending, [S::Assigned, S::Cancelled]],
    'finished' => [S::Finished, [S::Received]],
    'done negative' => [S::DoneNegative, [S::Received]],
    'cancelled' => [S::Cancelled, []],
]);

it('lets a job be finished only from handling', function () {
    $canFinish = array_filter(S::cases(), fn (S $status) => $status->canMoveTo(S::Finished));

    expect(array_values($canFinish))->toEqual([S::Handling]);
});

it('never lets a technician skip straight to finished', function () {
    foreach ([S::Received, S::Assigned, S::Accepted, S::OnTheWay, S::Arrived] as $status) {
        expect($status->canMoveTo(S::Finished))->toBeFalse();
    }
});

it('cannot cancel once the technician is at the merchant', function () {
    foreach ([S::Arrived, S::Handling, S::Finished, S::DoneNegative, S::Cancelled] as $status) {
        expect($status->canMoveTo(S::Cancelled))->toBeFalse();
    }
});

it('treats finished, done negative and cancelled as final, and pending as open', function () {
    expect(S::Finished->isFinal())->toBeTrue()
        ->and(S::DoneNegative->isFinal())->toBeTrue()
        ->and(S::Cancelled->isFinal())->toBeTrue()
        ->and(S::Pending->isFinal())->toBeFalse()
        ->and(S::Received->isFinal())->toBeFalse();
});

it('knows which statuses have a technician responsible', function () {
    $withTechnician = array_filter(S::cases(), fn (S $status) => $status->hasTechnician());

    expect(array_values($withTechnician))->toEqualCanonicalizing([S::Assigned, S::Accepted, S::OnTheWay, S::Arrived, S::Handling]);
});

it('can reach every status from received', function () {
    $seen = [S::Received];
    $queue = [S::Received];

    while ($queue !== []) {
        foreach (array_shift($queue)->allowedNext() as $next) {
            if (! in_array($next, $seen, true)) {
                $seen[] = $next;
                $queue[] = $next;
            }
        }
    }

    expect($seen)->toHaveCount(count(S::cases()));
});

it('can always leave a non-cancelled status, so no ticket is ever stuck', function () {
    foreach (S::cases() as $status) {
        if ($status !== S::Cancelled) {
            expect($status->allowedNext())->not->toBe([]);
        }
    }
});
