<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Application\Services\TicketWorkflow;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketAssetLine;

/**
 * Takes back a line noted by mistake, as long as the visit is still open (nothing has been applied yet).
 */
final class RemoveAssetLine
{
    public function __construct(private readonly TicketWorkflow $workflow) {}

    public function handle(Ticket $ticket, TicketAssetLine $line, User $actor, ?ActionContext $context = null): void
    {
        $context ??= new ActionContext;

        DB::transaction(function () use ($ticket, $line, $actor, $context): void {
            $current = $this->workflow->lock($ticket);
            $visit = $this->workflow->openVisit($current);
            $this->workflow->assertTechnician($visit, $actor, $context);

            $fresh = TicketAssetLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();

            if ($fresh->visit_id !== $visit->id || ! $fresh->isPending()) {
                throw ValidationException::withMessages(['line' => 'Catatan aset ini tidak dapat dihapus.']);
            }

            $fresh->delete();
        });
    }
}
