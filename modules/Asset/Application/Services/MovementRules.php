<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Enums\TrackingMode;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetHandover;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Asset\Domain\Models\Warehouse;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Domain\Models\Terminal;

/**
 * Every rule about whether a movement is allowed. The engine asks; it never decides on its own.
 */
final class MovementRules
{
    public function assertHolders(MovementType $type, Holder $from, Holder $to): void
    {
        if (! in_array($from->type, $type->fromHolders(), true)) {
            throw $this->fail('from', "{$type->label()} tidak dapat berasal dari {$from->type->label()}.");
        }

        if (! in_array($to->type, $type->toHolders(), true)) {
            throw $this->fail('to', "{$type->label()} tidak dapat menuju {$to->type->label()}.");
        }

        foreach (['from' => $from, 'to' => $to] as $field => $holder) {
            if ($holder->type !== HolderType::External && $holder->id === null) {
                throw $this->fail($field, 'Pemegang aset belum dipilih.');
            }
        }

        if ($type !== MovementType::Adjustment && $from->equals($to)) {
            throw $this->fail('to', 'Tujuan tidak boleh sama dengan asal.');
        }
    }

    /**
     * Both ends must exist and be usable. A technician must be allowed to receive assets.
     */
    public function assertEndpoints(MovementCommand $command): void
    {
        foreach (['from' => $command->from, 'to' => $command->to] as $field => $holder) {
            $this->assertHolderExists($holder, $field);
        }

        if ($command->to->type === HolderType::User && $command->type !== MovementType::Adjustment) {
            $receiver = User::query()->find($command->to->id);

            if (! $receiver instanceof User || ! $receiver->can(Permissions::ASSET_TRANSFER_RECEIVE)) {
                throw $this->fail('to', 'Penerima bukan teknisi yang berhak menerima aset.');
            }
        }
    }

    public function assertSerialItem(Asset $asset, MovementCommand $command, ?AssetHandover $fulfilling = null): void
    {
        $type = $command->type;
        $label = "Aset {$asset->serial_number}";

        if ($asset->model->category->tracking_mode !== TrackingMode::Serialized || ! $type->supports(TrackingMode::Serialized)) {
            throw $this->fail('items', "{$label} tidak dapat diproses dengan {$type->label()}.");
        }

        if ($fulfilling instanceof AssetHandover) {
            if ($asset->pending_handover_id !== $fulfilling->id) {
                throw $this->fail('items', "{$label} tidak lagi menunggu serah-terima ini.");
            }
        } elseif ($asset->status === AssetStatus::InTransit) {
            throw $this->fail('items', "{$label} sedang menunggu serah-terima lain.");
        } elseif (! in_array($asset->status, $type->allowedFromStatuses(), true)) {
            throw $this->fail('items', "{$label} berstatus {$asset->status->label()}; tidak dapat {$this->lower($type->label())}.");
        }

        if (! $asset->holder()->equals($command->from)) {
            throw $this->fail('items', "{$label} tidak berada di pemegang asal yang dipilih.");
        }

        if ($command->to->type === HolderType::Terminal) {
            $terminal = Terminal::query()->find($command->to->id);

            if ($terminal instanceof Terminal && $terminal->vendor_id !== $asset->owner_vendor_id) {
                throw $this->fail('to', "{$label} milik vendor lain dan tidak dapat dipasang di TID {$terminal->tid}.");
            }
        }
    }

    /**
     * Status the asset had when it entered this handover, which must still satisfy the movement it is waiting to become.
     */
    public function assertHandoverStatus(AssetStatus $fromStatus, Asset $asset, MovementType $type): void
    {
        if (! in_array($fromStatus, $type->allowedFromStatuses(), true)) {
            throw $this->fail('items', "Aset {$asset->serial_number} tidak dapat {$this->lower($type->label())} dari status {$fromStatus->label()}.");
        }
    }

    public function assertQuantityItem(MoveItem $item, AssetModel $model, MovementType $type): void
    {
        if ($model->category->tracking_mode !== TrackingMode::Quantity || ! $type->supports(TrackingMode::Quantity)) {
            throw $this->fail('items', "{$model->displayName()} tidak dapat diproses dengan {$type->label()}.");
        }

        if ($item->quantity === 0 || ($item->quantity < 0 && $type !== MovementType::Adjustment)) {
            throw $this->fail('items', 'Jumlah harus lebih dari nol.');
        }
    }

    private function assertHolderExists(Holder $holder, string $field): void
    {
        $exists = match ($holder->type) {
            HolderType::External => true,
            HolderType::Warehouse => Warehouse::query()->whereKey($holder->id)->where('is_active', true)->exists(),
            HolderType::User => User::query()->whereKey($holder->id)->where('is_active', true)->exists(),
            HolderType::Terminal => Terminal::query()->whereKey($holder->id)->exists(),
        };

        if (! $exists) {
            throw $this->fail($field, "{$holder->type->label()} tidak ditemukan atau tidak aktif.");
        }
    }

    private function fail(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }

    private function lower(string $text): string
    {
        return mb_strtolower($text);
    }
}
