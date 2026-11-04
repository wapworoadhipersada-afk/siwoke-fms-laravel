<?php

declare(strict_types=1);

namespace Modules\IAM;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\IAM\Domain\Enums\RoleName;

final class IAMServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        $this->registerAuthorization();
    }

    /**
     * Super Admin passes every permission check; everyone else is checked normally.
     */
    private function registerAuthorization(): void
    {
        Gate::before(static fn (?Authenticatable $user): ?bool => $user instanceof User && $user->hasRole(RoleName::SuperAdmin->value) ? true : null);
    }
}
