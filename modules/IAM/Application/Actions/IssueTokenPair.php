<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\IAM\Application\DTOs\TokenPair;
use Modules\IAM\Domain\Models\Device;
use Modules\IAM\Domain\Models\RefreshToken;

/**
 * Mints a short-lived access token and a long-lived refresh token for one device.
 * Only the SHA-256 of the refresh token is stored, so a database leak cannot be replayed.
 */
final class IssueTokenPair
{
    public function handle(User $user, Device $device, string $family): TokenPair
    {
        $accessExpiresAt = now()->addMinutes((int) config('iam.access_token_ttl_minutes'));
        $access = $user->createToken('device:'.$device->device_uid, ['mobile'], $accessExpiresAt);
        $access->accessToken->forceFill(['device_id' => $device->id])->save();

        $refreshExpiresAt = now()->addDays((int) config('iam.refresh_token_ttl_days'));
        $plainRefreshToken = Str::random(64);

        $refresh = RefreshToken::query()->create([
            'user_id' => $user->id,
            'device_id' => $device->id,
            'family' => $family,
            'token_hash' => hash('sha256', $plainRefreshToken),
            'expires_at' => $refreshExpiresAt,
        ]);

        return new TokenPair(
            accessToken: $access->plainTextToken,
            accessExpiresAt: $accessExpiresAt,
            refreshToken: $plainRefreshToken,
            refreshExpiresAt: $refreshExpiresAt,
            refreshTokenId: $refresh->id,
        );
    }
}
