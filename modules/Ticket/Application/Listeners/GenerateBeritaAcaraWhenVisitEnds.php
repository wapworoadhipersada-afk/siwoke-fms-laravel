<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Ticket\Application\BeritaAcara\GenerateBeritaAcara;
use Modules\Ticket\Domain\Enums\TicketEvent;
use Modules\Ticket\Domain\Events\TicketStatusChanged;
use Modules\Ticket\Domain\Models\TicketStatusHistory;
use Modules\Ticket\Domain\Models\TicketVisit;

/**
 * Runs in the background (a PDF with photos takes a moment, and the technician should not wait for it).
 * It reacts only to the three ways a visit can end; every other status change is ignored. If it fails it is
 * retried, and because the action returns the existing report when there is one, a retry never makes a second.
 */
final class GenerateBeritaAcaraWhenVisitEnds implements ShouldQueue
{
    public int $tries = 4;

    public function __construct(private readonly GenerateBeritaAcara $generate) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(TicketStatusChanged $event): void
    {
        if (! (bool) config('tickets.berita_acara.auto_generate')) {
            return;
        }

        $history = TicketStatusHistory::query()->find($event->historyId);

        if (! $history instanceof TicketStatusHistory || $history->visit_id === null) {
            return;
        }

        if (! in_array($history->event, [TicketEvent::Finished, TicketEvent::Pended, TicketEvent::ClosedNegative], true)) {
            return;
        }

        $visit = TicketVisit::query()->find($history->visit_id);

        if ($visit instanceof TicketVisit) {
            $this->generate->handle($visit);
        }
    }
}
