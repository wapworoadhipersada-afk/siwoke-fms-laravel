<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use Laravel\Sanctum\PersonalAccessToken;
use Modules\IAM\Domain\Models\Device;
use Modules\IAM\Domain\Models\RefreshToken;

final class RevokeDeviceSessions
{
    public function handle(Device $device): void
    {
        PersonalAccessToken::query()->where('device_id', $device->id)->delete();

        RefreshToken::query()
            ->where('device_id', $device->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
