<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Str;
use Modules\Shared\Support\Audit;

/**
 * Audits dashboard (session) sign-ins. Mobile sign-ins are audited by their own actions.
 */
final class LogWebAuthentication
{
    public function handle(Login|Failed|Logout $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        match (true) {
            $event instanceof Login => $this->loggedIn($event),
            $event instanceof Failed => $this->failed($event),
            $event instanceof Logout => $this->loggedOut($event),
        };
    }

    private function loggedIn(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        Audit::record('auth', 'web_login', 'Login web berhasil', $user, $user);
    }

    private function failed(Failed $event): void
    {
        // Never record the submitted password, only which account was targeted.
        $email = $event->credentials['email'] ?? null;

        Audit::record('auth', 'web_login_failed', 'Login web gagal', properties: [
            'email' => is_string($email) ? Str::lower($email) : null,
        ]);
    }

    private function loggedOut(Logout $event): void
    {
        $user = $event->user;

        if ($user instanceof User) {
            Audit::record('auth', 'web_logout', 'Logout web', $user, $user);
        }
    }
}
