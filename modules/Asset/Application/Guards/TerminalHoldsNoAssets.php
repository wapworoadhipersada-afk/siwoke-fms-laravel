<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Guards;

use Illuminate\Validation\ValidationException;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Models\Asset;
use Modules\Master\Application\Contracts\TerminalDeletionGuard;
use Modules\Master\Domain\Models\Terminal;

/**
 * A TID that still has assets installed cannot be deleted: they would be left held by something that no longer exists.
 */
final class TerminalHoldsNoAssets implements TerminalDeletionGuard
{
    public function assertCanDelete(Terminal $terminal): void
    {
        $count = Asset::query()->where('holder_type', HolderType::Terminal->value)->where('holder_id', $terminal->id)->count();

        if ($count > 0) {
            throw ValidationException::withMessages(['terminal' => "TID {$terminal->tid} masih memiliki {$count} aset terpasang. Tarik asetnya lebih dulu."]);
        }
    }
}
