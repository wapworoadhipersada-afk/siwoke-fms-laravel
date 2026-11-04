<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Actions;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Asset\Application\Services\HandoverAuthority;
use Modules\Asset\Application\Services\HandoverRelease;
use Modules\Asset\Domain\Enums\HandoverStatus;
use Modules\Asset\Domain\Models\AssetHandover;

/**
 * The receiving side refuses (wrong item, damaged, not expected). The assets go back to the sender
 * and no ledger entry is written, because nothing moved.
 */
final class RejectHandover
{
    public function __construct(
        private readonly HandoverAuthority $authority,
        private readonly HandoverRelease $release,
    ) {}

    public function handle(AssetHandover $handover, User $actor, string $reason): AssetHandover
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan penolakan wajib diisi.']);
        }

        return DB::transaction(function () use ($handover, $actor, $reason): AssetHandover {
            $current = AssetHandover::query()->whereKey($handover->id)->lockForUpdate()->firstOrFail();

            if ($current->status !== HandoverStatus::Pending) {
                throw ValidationException::withMessages(['handover' => 'Serah-terima ini sudah diselesaikan.']);
            }

            if (! $this->authority->canResolve($actor, $current)) {
                throw new AuthorizationException('Anda bukan penerima serah-terima ini.');
            }

            $this->release->release($current);

            $current->forceFill([
                'status' => HandoverStatus::Rejected,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
                'resolution_note' => $reason,
            ])->save();

            return $current;
        });
    }
}
