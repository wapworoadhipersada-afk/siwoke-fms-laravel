<?php

declare(strict_types=1);

namespace Modules\IAM;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Modules\IAM\Application\Listeners\LogAccessChange;
use Modules\IAM\Application\Listeners\LogWebAuthentication;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Http\Api\V1\Middleware\EnsureMobileSession;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

final class IAMServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        $this->registerAuthorization();
        $this->registerAuditListeners();
        $this->registerRateLimiters();
        $this->registerRoutes();
    }

    /**
     * Super Admin passes every permission check; everyone else is checked normally.
     */
    private function registerAuthorization(): void
    {
        Gate::before(static fn (?Authenticatable $user): ?bool => $user instanceof User && $user->hasRole(RoleName::SuperAdmin->value) ? true : null);
    }

    private function registerAuditListeners(): void
    {
        Event::listen([Login::class, Failed::class, Logout::class], LogWebAuthentication::class);

        Event::listen(
            [RoleAttachedEvent::class, RoleDetachedEvent::class, PermissionAttachedEvent::class, PermissionDetachedEvent::class],
            LogAccessChange::class,
        );
    }

    private function registerRateLimiters(): void
    {
        // Brute force: throttle per email+IP, plus a looser per-IP ceiling against credential stuffing.
        RateLimiter::for('mobile-login', static fn (Request $request): array => [
            Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by((string) $request->ip()),
        ]);

        RateLimiter::for('mobile-refresh', static fn (Request $request): Limit => Limit::perMinute(20)->by((string) $request->ip()));

        // Authenticated traffic is limited per user and device, so one noisy phone cannot starve the rest.
        RateLimiter::for('mobile', static fn (Request $request): Limit => Limit::perMinute(120)->by(
            ($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.$request->header('X-Device-Id', ''),
        ));
    }

    private function registerRoutes(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('mobile.session', EnsureMobileSession::class);

        Route::middleware('api')
            ->prefix('api/mobile/v1')
            ->name('api.mobile.v1.')
            ->group(__DIR__.'/routes/api_v1.php');
    }
}
