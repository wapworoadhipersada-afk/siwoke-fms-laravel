<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Modules\Asset\Application\Actions\MoveAssets;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Domain\Enums\AssetLineAction;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketAssetLine;
use Modules\Ticket\Domain\Models\TicketVisit;

/**
 * Turns the assets noted on a visit into real custody changes when the visit ends. It runs inside the close
 * transaction, so a movement the ledger refuses stops the close and nothing is half done. A visit that does
 * not finish applies nothing: its notes are kept, marked void.
 */
final class SettleVisitAssets
{
    public function __construct(private readonly MoveAssets $movements) {}

    public function handle(Ticket $ticket, TicketVisit $visit, VisitOutcome $outcome, User $actor, CarbonInterface $at, ActionContext $context): void
    {
        $lines = TicketAssetLine::query()->where('visit_id', $visit->id)->whereNull('applied_movement_id')->whereNull('voided_at')->orderBy('id')->lockForUpdate()->get();

        foreach ($lines as $line) {
            if ($outcome !== VisitOutcome::Finish) {
                $line->forceFill(['voided_at' => $at, 'void_reason' => "Kunjungan berakhir {$outcome->label()}"])->save();

                continue;
            }

            $movement = $this->movements->handle($this->command($ticket, $visit, $line, $actor, $at, $context));
            $line->forceFill(['applied_movement_id' => $movement->id])->save();
        }
    }

    private function command(Ticket $ticket, TicketVisit $visit, TicketAssetLine $line, User $actor, CarbonInterface $at, ActionContext $context): MovementCommand
    {
        $technician = Holder::user($visit->technician_id);
        $terminal = Holder::terminal($ticket->terminal_id);

        [$type, $from, $to] = match ($line->action) {
            AssetLineAction::Install => [MovementType::Install, $technician, $terminal],
            AssetLineAction::Uninstall => [MovementType::Uninstall, $terminal, $technician],
            AssetLineAction::Consume => [MovementType::Consume, $technician, Holder::external()],
        };

        $item = $line->action === AssetLineAction::Consume
            ? MoveItem::stock($line->asset_model_id, $line->owner_vendor_id, $line->quantity)
            : MoveItem::asset(Asset::query()->findOrFail($line->asset_id));

        return new MovementCommand(
            type: $type,
            items: [$item],
            from: $from,
            to: $to,
            actor: $actor,
            notes: "Tiket {$ticket->ticket_no}, kunjungan {$visit->visit_no}",
            referenceType: 'ticket',
            referenceId: $ticket->id,
            occurredAt: $at,
            latitude: $context->latitude,
            longitude: $context->longitude,
            deviceId: $context->deviceId,
        );
    }
}
