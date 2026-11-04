<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\IAM\Application\DTOs\AuthSession;
use Modules\IAM\Domain\Models\Device;
use Modules\IAM\Domain\Permissions;
use Modules\Shared\Http\ProblemException;
use Modules\Shared\Support\Audit;

final class LoginMobileUser
{
    public function __construct(
        private readonly IssueTokenPair $issueTokenPair,
        private readonly RevokeDeviceSessions $revokeDeviceSessions,
    ) {}

    /**
     * @param  array{email: string, password: string, device_uid: string, platform: string, device_name?: string|null, app_version?: string|null, push_token?: string|null}  $data
     */
    public function handle(array $data): AuthSession
    {
        $email = Str::lower($data['email']);
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        // Always run a hash comparison so an unknown email costs the same time as a wrong password.
        $passwordMatches = Hash::check($data['password'], $user instanceof User ? $user->password : Hash::make(Str::random(16)));

        if (! $user instanceof User || ! $passwordMatches) {
            Audit::record('auth', 'login_failed', 'Login mobile gagal', properties: [
                'email' => $email,
                'device_uid' => $data['device_uid'],
            ]);

            throw ProblemException::unauthenticated('invalid_credentials', 'Email atau password salah.');
        }

        if (! $user->is_active || ! $user->can(Permissions::MOBILE_ACCESS)) {
            Audit::record('auth', 'login_denied', 'Login mobile ditolak', $user, $user, ['device_uid' => $data['device_uid']]);

            throw ProblemException::forbidden('account_not_permitted', 'Akun tidak aktif atau tidak memiliki akses mobile.');
        }

        $device = Device::query()->firstOrNew(['user_id' => $user->id, 'device_uid' => $data['device_uid']]);

        if ($device->isBlocked()) {
            Audit::record('auth', 'login_denied', 'Login mobile dari perangkat terblokir', $user, $user, ['device_uid' => $data['device_uid']]);

            throw ProblemException::forbidden('device_blocked', 'Perangkat ini diblokir.');
        }

        $device->fill([
            'platform' => $data['platform'],
            'device_name' => $data['device_name'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'push_token' => $data['push_token'] ?? null,
            'last_seen_at' => now(),
            'last_ip' => request()->ip(),
        ])->save();

        return DB::transaction(function () use ($user, $device): AuthSession {
            // One live session per device: logging in again replaces the previous one.
            $this->revokeDeviceSessions->handle($device);

            $tokens = $this->issueTokenPair->handle($user, $device, (string) Str::uuid());

            $user->forceFill(['last_login_at' => now()])->save();

            Audit::record('auth', 'login', 'Login mobile berhasil', $user, $user, ['device_uid' => $device->device_uid]);

            return new AuthSession($user, $tokens);
        });
    }
}
