<?php

declare(strict_types=1);

namespace Modules\IAM;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\IAM\Application\Listeners\LogAccessChange;
use Modules\IAM\Domain\Enums\RoleName;
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
        Event::listen(
            [RoleAttachedEvent::class, RoleDetachedEvent::class, PermissionAttachedEvent::class, PermissionDetachedEvent::class],
            LogAccessChange::class,
        );
    }
}
