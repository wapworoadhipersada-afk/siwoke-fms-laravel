<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Services;

use Modules\Document\Application\Services\AttachmentCounter;
use Modules\Document\Domain\Enums\AttachmentKind;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketType;
use Modules\Ticket\Domain\Models\TicketVisit;

/**
 * What evidence a visit must carry before it may end with a given result, as configured per ticket type.
 */
final class EvidenceRules
{
    public function __construct(private readonly AttachmentCounter $counter) {}

    /**
     * @return array<string, int> kind value => how many are required
     */
    public function requiredFor(TicketType $type, VisitOutcome $outcome): array
    {
        $rules = $type->evidence_rules[$outcome->value] ?? [];

        if (! is_array($rules)) {
            return [];
        }

        $required = [];

        foreach ($rules as $kind => $count) {
            if (AttachmentKind::tryFrom((string) $kind) instanceof AttachmentKind && (int) $count > 0) {
                $required[(string) $kind] = (int) $count;
            }
        }

        return $required;
    }

    /**
     * What is still missing, one readable sentence per kind.
     *
     * @return list<string>
     */
    public function missing(Ticket $ticket, TicketVisit $visit, VisitOutcome $outcome): array
    {
        $required = $this->requiredFor($ticket->type, $outcome);

        if ($required === []) {
            return [];
        }

        $have = $this->counter->countByKind($visit);
        $missing = [];

        foreach ($required as $kind => $count) {
            if (($have[$kind] ?? 0) < $count) {
                $label = AttachmentKind::from($kind)->label();
                $missing[] = "{$label}: butuh {$count}, baru ada ".($have[$kind] ?? 0).'.';
            }
        }

        return $missing;
    }

    /**
     * For the mobile app: per result, what is required and how much is already attached.
     *
     * @return array<string, array<string, array{label: string, required: int, have: int}>>
     */
    public function summary(Ticket $ticket, TicketVisit $visit): array
    {
        $have = $this->counter->countByKind($visit);
        $summary = [];

        foreach ([VisitOutcome::Finish, VisitOutcome::Pending, VisitOutcome::DoneNegative] as $outcome) {
            foreach ($this->requiredFor($ticket->type, $outcome) as $kind => $count) {
                $summary[$outcome->value][$kind] = [
                    'label' => AttachmentKind::from($kind)->label(),
                    'required' => $count,
                    'have' => $have[$kind] ?? 0,
                ];
            }
        }

        return $summary;
    }
}
