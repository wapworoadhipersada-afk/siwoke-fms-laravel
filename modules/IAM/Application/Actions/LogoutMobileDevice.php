<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use App\Models\User;
use Modules\IAM\Domain\Models\Device;
use Modules\Shared\Support\Audit;

final class LogoutMobileDevice
{
    public function __construct(private readonly RevokeDeviceSessions $revokeDeviceSessions) {}

    public function handle(User $user, ?int $deviceId): void
    {
        $device = $deviceId === null
            ? null
            : Device::query()->where('user_id', $user->id)->find($deviceId);

        if ($device instanceof Device) {
            $this->revokeDeviceSessions->handle($device);
        }

        Audit::record('auth', 'logout', 'Logout mobile', $user, $user, ['device_uid' => $device?->device_uid]);
    }
}
