<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Application\Services\LedgerWriter;
use Modules\Asset\Application\Services\MovementRules;
use Modules\Asset\Application\Services\StockBook;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Enums\TrackingMode;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Asset\Domain\Models\AssetMovement;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Master\Domain\Models\Vendor;

/**
 * Corrects a balance after a physical count. The correction is itself a ledger entry with a mandatory
 * reason, so a stock count never silently overwrites history.
 */
final class AdjustStock
{
    public function __construct(
        private readonly LedgerWriter $ledger,
        private readonly StockBook $stock,
        private readonly MovementRules $rules,
    ) {}

    public function handle(AssetModel $model, Vendor $vendor, Holder $holder, int $delta, User $actor, string $reason): AssetMovement
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan penyesuaian wajib diisi.']);
        }

        if ($delta === 0) {
            throw ValidationException::withMessages(['delta' => 'Selisih tidak boleh nol.']);
        }

        if ($model->category->tracking_mode !== TrackingMode::Quantity) {
            throw ValidationException::withMessages(['asset_model' => 'Hanya barang berbasis jumlah yang dapat disesuaikan.']);
        }

        $command = new MovementCommand(
            type: MovementType::Adjustment,
            items: [MoveItem::stock($model, $vendor, $delta)],
            from: $holder,
            to: $holder,
            actor: $actor,
            notes: $reason,
        );

        $this->rules->assertHolders($command->type, $holder, $holder);
        $this->rules->assertEndpoints($command);

        return DB::transaction(function () use ($command, $model, $vendor, $holder, $delta): AssetMovement {
            $this->stock->change($model->id, $vendor->id, $holder, $delta);

            return $this->ledger->record($command);
        });
    }
}
