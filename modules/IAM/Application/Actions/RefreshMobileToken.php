<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\IAM\Application\DTOs\AuthSession;
use Modules\IAM\Domain\Models\RefreshToken;
use Modules\Shared\Http\ProblemException;
use Modules\Shared\Support\Audit;

/**
 * Rotates a refresh token: the presented token is spent and a new pair is issued.
 * Presenting an already-spent token means it was copied, so every session on that device is revoked.
 */
final class RefreshMobileToken
{
    public function __construct(
        private readonly IssueTokenPair $issueTokenPair,
        private readonly RevokeDeviceSessions $revokeDeviceSessions,
    ) {}

    public function handle(string $refreshToken, string $deviceUid): AuthSession
    {
        // Failures are returned (not thrown) so the revocations below are committed before we respond.
        $outcome = DB::transaction(fn (): AuthSession|ProblemException => $this->rotate($refreshToken, $deviceUid));

        if ($outcome instanceof ProblemException) {
            throw $outcome;
        }

        return $outcome;
    }

    private function rotate(string $refreshToken, string $deviceUid): AuthSession|ProblemException
    {
        $record = RefreshToken::query()
            ->where('token_hash', hash('sha256', $refreshToken))
            ->lockForUpdate()
            ->first();

        if (! $record instanceof RefreshToken) {
            return ProblemException::unauthenticated('invalid_refresh_token', 'Refresh token tidak valid.');
        }

        $device = $record->device;
        $user = $record->user;

        if ($device->device_uid !== $deviceUid) {
            $this->revokeDeviceSessions->handle($device);
            Audit::record('auth', 'refresh_device_mismatch', 'Refresh token dipakai dari perangkat lain', $user, $user, [
                'expected_device_uid' => $device->device_uid,
                'presented_device_uid' => $deviceUid,
            ]);

            return ProblemException::unauthenticated('invalid_refresh_token', 'Refresh token tidak valid.');
        }

        if ($record->used_at !== null) {
            $this->revokeDeviceSessions->handle($device);
            Audit::record('auth', 'refresh_token_reuse_detected', 'Refresh token bekas dipakai ulang; semua sesi perangkat dicabut', $user, $user, [
                'device_uid' => $device->device_uid,
                'family' => $record->family,
            ]);

            return ProblemException::unauthenticated('token_reuse_detected', 'Sesi dicabut karena terdeteksi penggunaan ulang token. Silakan login kembali.');
        }

        if ($record->revoked_at !== null || $record->expires_at->isPast()) {
            return ProblemException::unauthenticated('invalid_refresh_token', 'Refresh token tidak valid atau sudah kedaluwarsa.');
        }

        if (! $user->is_active || $device->isBlocked()) {
            $this->revokeDeviceSessions->handle($device);

            return ProblemException::forbidden('account_not_permitted', 'Akun tidak aktif atau perangkat diblokir.');
        }

        $record->forceFill(['used_at' => now()])->save();

        // The old access token dies with the old refresh token.
        $this->revokeAccessTokens($device->id);

        $tokens = $this->issueTokenPair->handle($user, $device, $record->family);
        $record->forceFill(['replaced_by_id' => $tokens->refreshTokenId])->save();

        $device->forceFill(['last_seen_at' => now(), 'last_ip' => request()->ip()])->save();

        Audit::record('auth', 'token_refreshed', 'Token mobile diperbarui', $user, $user, ['device_uid' => $device->device_uid]);

        return new AuthSession($user, $tokens);
    }

    private function revokeAccessTokens(int $deviceId): void
    {
        PersonalAccessToken::query()->where('device_id', $deviceId)->delete();
    }
}
