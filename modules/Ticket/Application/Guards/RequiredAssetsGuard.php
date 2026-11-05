<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Guards;

use Illuminate\Validation\ValidationException;
use Modules\Ticket\Application\Contracts\ClosureGuard;
use Modules\Ticket\Domain\Enums\AssetLineAction;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketAssetLine;
use Modules\Ticket\Domain\Models\TicketVisit;

/**
 * A visit cannot finish until the assets its ticket type expects are recorded: a pull-out needs the machine
 * taken out, a replacement needs one out and one in. Types without rules ask for nothing.
 */
final class RequiredAssetsGuard implements ClosureGuard
{
    public function assertCanClose(Ticket $ticket, TicketVisit $visit, VisitOutcome $outcome): void
    {
        $rules = $ticket->type->asset_rules[$outcome->value] ?? [];

        if (! is_array($rules) || $rules === []) {
            return;
        }

        $missing = [];

        foreach ($rules as $key => $required) {
            $action = AssetLineAction::tryFrom((string) $key);

            if (! $action instanceof AssetLineAction || (int) $required < 1) {
                continue;
            }

            $have = TicketAssetLine::query()->where('visit_id', $visit->id)->whereNull('voided_at')->where('action', $action->value)->count();

            if ($have < (int) $required) {
                $missing[] = "{$action->label()}: butuh {$required}, baru ada {$have}.";
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages(['assets' => 'Aset belum lengkap. '.implode(' ', $missing)]);
        }
    }
}
