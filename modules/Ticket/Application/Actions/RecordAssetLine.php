<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Application\Services\StockBook;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Enums\TrackingMode;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Application\Services\TicketWorkflow;
use Modules\Ticket\Domain\Enums\AssetLineAction;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketAssetLine;
use Modules\Ticket\Domain\Models\TicketVisit;

/**
 * The technician notes an asset they installed, pulled out, or used up at the merchant. Nothing moves in the
 * asset ledger yet (the visit might still end pending); this only checks the line makes sense now, so a
 * mistake is caught while the technician is still standing in front of the machine.
 */
final class RecordAssetLine
{
    public function __construct(
        private readonly TicketWorkflow $workflow,
        private readonly StockBook $stock,
    ) {}

    public function handle(
        Ticket $ticket,
        User $actor,
        AssetLineAction $action,
        ?Asset $asset = null,
        ?AssetModel $model = null,
        int $quantity = 1,
        ?string $notes = null,
        ?ActionContext $context = null,
    ): TicketAssetLine {
        $context ??= new ActionContext;

        return DB::transaction(function () use ($ticket, $actor, $action, $asset, $model, $quantity, $notes, $context): TicketAssetLine {
            $current = $this->workflow->lock($ticket);

            // A phone resending after a bad connection gets the original line back.
            if ($context->clientUuid !== null) {
                $existing = TicketAssetLine::query()->where('client_uuid', $context->clientUuid)->first();

                if ($existing instanceof TicketAssetLine) {
                    if ($existing->ticket_id !== $current->id) {
                        throw ValidationException::withMessages(['client_uuid' => 'Kode ini sudah dipakai untuk tiket lain.']);
                    }

                    return $existing;
                }
            }

            if ($current->status !== TicketStatus::Handling) {
                throw ValidationException::withMessages(['status' => 'Aset hanya dapat dicatat saat tiket sedang dikerjakan.']);
            }

            $visit = $this->workflow->openVisit($current);
            $this->workflow->assertTechnician($visit, $actor, $context);

            [$assetModelId, $vendorId, $assetId, $amount] = $action === AssetLineAction::Consume
                ? $this->consumable($current, $visit, $model, $quantity)
                : $this->serialized($current, $visit, $action, $asset);

            $line = new TicketAssetLine([
                'ticket_id' => $current->id,
                'visit_id' => $visit->id,
                'action' => $action,
                'asset_id' => $assetId,
                'asset_model_id' => $assetModelId,
                'owner_vendor_id' => $vendorId,
                'quantity' => $amount,
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
                'client_uuid' => $context->clientUuid,
                'created_by' => $actor->id,
            ]);
            $line->save();

            return $line;
        });
    }

    /**
     * @return array{int, int, int, int}
     */
    private function serialized(Ticket $ticket, TicketVisit $visit, AssetLineAction $action, ?Asset $asset): array
    {
        if (! $asset instanceof Asset) {
            throw ValidationException::withMessages(['asset' => 'Pilih asetnya.']);
        }

        $asset->loadMissing('model.category');
        $label = "Aset {$asset->serial_number}";

        if ($asset->model->category->tracking_mode !== TrackingMode::Serialized) {
            throw ValidationException::withMessages(['asset' => "{$label} adalah barang habis pakai; catat sebagai pemakaian."]);
        }

        if ($asset->owner_vendor_id !== $ticket->vendor_id) {
            throw ValidationException::withMessages(['asset' => "{$label} milik vendor lain dan tidak boleh dipakai di tiket ini."]);
        }

        if ($action === AssetLineAction::Install) {
            $inHands = $asset->status === AssetStatus::WithTechnician
                && $asset->holder_type === HolderType::User
                && $asset->holder_id === $visit->technician_id;

            if (! $inHands) {
                throw ValidationException::withMessages(['asset' => "{$label} tidak berada pada teknisi kunjungan ini."]);
            }
        } else {
            $atTerminal = $asset->status === AssetStatus::Installed
                && $asset->holder()->equals(Holder::terminal($ticket->terminal_id));

            if (! $atTerminal) {
                throw ValidationException::withMessages(['asset' => "{$label} tidak terpasang di TID tiket ini."]);
            }
        }

        $alreadyListed = TicketAssetLine::query()->where('visit_id', $visit->id)->where('asset_id', $asset->id)->whereNull('voided_at')->exists();

        if ($alreadyListed) {
            throw ValidationException::withMessages(['asset' => "{$label} sudah dicatat pada kunjungan ini."]);
        }

        return [$asset->asset_model_id, $asset->owner_vendor_id, $asset->id, 1];
    }

    /**
     * @return array{int, int, null, int}
     */
    private function consumable(Ticket $ticket, TicketVisit $visit, ?AssetModel $model, int $quantity): array
    {
        if (! $model instanceof AssetModel) {
            throw ValidationException::withMessages(['asset_model' => 'Pilih jenis barangnya.']);
        }

        $model->loadMissing('category');

        if ($model->category->tracking_mode !== TrackingMode::Quantity) {
            throw ValidationException::withMessages(['asset_model' => "{$model->displayName()} dilacak per unit; catat sebagai pemasangan atau penarikan."]);
        }

        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Jumlah harus lebih dari nol.']);
        }

        // What this visit already plans to use counts against what the technician carries.
        $planned = (int) TicketAssetLine::query()
            ->where('visit_id', $visit->id)->where('asset_model_id', $model->id)->whereNull('voided_at')
            ->where('action', AssetLineAction::Consume->value)->sum('quantity');
        $available = $this->stock->available($model->id, $ticket->vendor_id, Holder::user($visit->technician_id));

        if ($planned + $quantity > $available) {
            throw ValidationException::withMessages(['quantity' => "Stok {$model->displayName()} pada teknisi tidak cukup: tersedia {$available}, diminta ".($planned + $quantity).'.']);
        }

        return [$model->id, $ticket->vendor_id, null, $quantity];
    }
}
