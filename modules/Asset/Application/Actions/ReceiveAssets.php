<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Actions;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Application\Services\LedgerWriter;
use Modules\Asset\Application\Services\StockBook;
use Modules\Asset\Domain\Enums\AssetCondition;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Enums\TrackingMode;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Asset\Domain\Models\AssetMovement;
use Modules\Asset\Domain\Models\Warehouse;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Master\Domain\Models\Vendor;

/**
 * Brings new assets into a warehouse. Serialized models arrive as a list of serial numbers,
 * consumables as a quantity. Either way it is one ledger movement.
 */
final class ReceiveAssets
{
    private const MAX_UNITS = 500;

    public function __construct(
        private readonly LedgerWriter $ledger,
        private readonly StockBook $stock,
    ) {}

    /**
     * @param  list<string>  $serials  serial numbers, for serialized models
     * @param  int  $quantity  amount, for consumables
     */
    public function handle(
        AssetModel $model,
        Vendor $vendor,
        Warehouse $warehouse,
        User $actor,
        array $serials = [],
        int $quantity = 0,
        ?string $notes = null,
        AssetCondition $condition = AssetCondition::Good,
    ): AssetMovement {
        $this->assertUsable($model, $vendor, $warehouse);

        $mode = $model->category->tracking_mode;
        $serials = $mode === TrackingMode::Serialized ? $this->cleanSerials($model, $serials) : [];

        if ($mode === TrackingMode::Quantity && ($quantity < 1 || $quantity > 100000)) {
            throw ValidationException::withMessages(['quantity' => 'Jumlah harus antara 1 dan 100.000.']);
        }

        try {
            return DB::transaction(fn (): AssetMovement => $mode === TrackingMode::Serialized
                ? $this->receiveSerialized($model, $vendor, $warehouse, $actor, $serials, $notes, $condition)
                : $this->receiveQuantity($model, $vendor, $warehouse, $actor, $quantity, $notes));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['serials' => 'Salah satu nomor seri baru saja didaftarkan oleh orang lain.']);
        }
    }

    private function assertUsable(AssetModel $model, Vendor $vendor, Warehouse $warehouse): void
    {
        if (! $model->is_active || ! $model->category->is_active) {
            throw ValidationException::withMessages(['asset_model' => 'Jenis aset ini tidak aktif.']);
        }

        if (! $vendor->is_active) {
            throw ValidationException::withMessages(['vendor' => 'Vendor pemilik tidak aktif.']);
        }

        if (! $warehouse->is_active) {
            throw ValidationException::withMessages(['warehouse' => 'Gudang tidak aktif.']);
        }
    }

    /**
     * @param  list<string>  $serials
     * @return list<string>
     */
    private function cleanSerials(AssetModel $model, array $serials): array
    {
        $clean = array_values(array_filter(array_map('trim', $serials), static fn (string $serial): bool => $serial !== ''));

        if ($clean === []) {
            throw ValidationException::withMessages(['serials' => 'Isi minimal satu nomor seri.']);
        }

        if (count($clean) > self::MAX_UNITS) {
            throw ValidationException::withMessages(['serials' => 'Maksimal '.self::MAX_UNITS.' unit per penerimaan.']);
        }

        $lower = array_map('mb_strtolower', $clean);
        $duplicates = array_keys(array_filter(array_count_values($lower), static fn (int $count): bool => $count > 1));

        if ($duplicates !== []) {
            throw ValidationException::withMessages(['serials' => 'Nomor seri ganda dalam daftar: '.implode(', ', $duplicates).'.']);
        }

        $existing = Asset::query()
            ->where('asset_model_id', $model->id)
            ->whereIn(DB::raw('lower(serial_number)'), $lower)
            ->pluck('serial_number')
            ->all();

        if ($existing !== []) {
            throw ValidationException::withMessages(['serials' => 'Nomor seri sudah terdaftar: '.implode(', ', $existing).'.']);
        }

        return $clean;
    }

    /**
     * @param  list<string>  $serials
     */
    private function receiveSerialized(AssetModel $model, Vendor $vendor, Warehouse $warehouse, User $actor, array $serials, ?string $notes, AssetCondition $condition): AssetMovement
    {
        $assets = array_map(static function (string $serial) use ($model, $vendor, $warehouse, $condition): Asset {
            $asset = new Asset([
                'asset_model_id' => $model->id,
                'owner_vendor_id' => $vendor->id,
                'serial_number' => $serial,
                'condition' => $condition,
            ]);

            $asset->forceFill([
                'status' => AssetStatus::InStock,
                'holder_type' => HolderType::Warehouse,
                'holder_id' => $warehouse->id,
            ])->save();

            return $asset;
        }, $serials);

        $movement = $this->ledger->record(new MovementCommand(
            type: MovementType::Receive,
            items: array_map(static fn (Asset $asset): MoveItem => MoveItem::asset($asset), $assets),
            from: Holder::external(),
            to: Holder::warehouse($warehouse),
            actor: $actor,
            notes: $notes,
        ));

        Asset::query()->whereIn('id', array_map(static fn (Asset $asset): int => $asset->id, $assets))
            ->update(['last_movement_id' => $movement->id, 'version' => 1]);

        return $movement;
    }

    private function receiveQuantity(AssetModel $model, Vendor $vendor, Warehouse $warehouse, User $actor, int $quantity, ?string $notes): AssetMovement
    {
        $movement = $this->ledger->record(new MovementCommand(
            type: MovementType::Receive,
            items: [MoveItem::stock($model, $vendor, $quantity)],
            from: Holder::external(),
            to: Holder::warehouse($warehouse),
            actor: $actor,
            notes: $notes,
        ));

        $this->stock->change($model->id, $vendor->id, Holder::warehouse($warehouse), $quantity);

        return $movement;
    }
}
