<?php

declare(strict_types=1);

namespace Modules\IAM\Http\Api\V1\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\IAM\Domain\Models\Device;
use Modules\Shared\Http\ProblemException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `auth:sanctum`. A token stays cryptographically valid after an admin
 * disables the account or blocks the device, so this check cuts access immediately.
 */
final class EnsureMobileSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_active) {
            throw ProblemException::forbidden('account_not_permitted', 'Akun tidak aktif.');
        }

        $token = $user->currentAccessToken();
        $deviceId = $token instanceof PersonalAccessToken ? $token->getAttribute('device_id') : null;

        if ($deviceId !== null && Device::query()->whereKey($deviceId)->whereNotNull('blocked_at')->exists()) {
            throw ProblemException::forbidden('device_blocked', 'Perangkat ini diblokir.');
        }

        return $next($request);
    }
}
