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
 * The sender (or an approver) withdraws an offer that has not been answered yet.
 */
final class CancelHandover
{
    public function __construct(
        private readonly HandoverAuthority $authority,
        private readonly HandoverRelease $release,
    ) {}

    public function handle(AssetHandover $handover, User $actor, ?string $reason = null): AssetHandover
    {
        return DB::transaction(function () use ($handover, $actor, $reason): AssetHandover {
            $current = AssetHandover::query()->whereKey($handover->id)->lockForUpdate()->firstOrFail();

            if ($current->status !== HandoverStatus::Pending) {
                throw ValidationException::withMessages(['handover' => 'Serah-terima ini sudah diselesaikan.']);
            }

            if (! $this->authority->canCancel($actor, $current)) {
                throw new AuthorizationException('Hanya pengirim atau approver yang dapat membatalkan serah-terima.');
            }

            $this->release->release($current);

            $current->forceFill([
                'status' => HandoverStatus::Cancelled,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
                'resolution_note' => $reason,
            ])->save();

            return $current;
        });
    }
}
