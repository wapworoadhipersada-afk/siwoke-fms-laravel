<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Listeners;

use Illuminate\Support\Collection;
use Modules\Shared\Support\Audit;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Records who granted or revoked a role or permission, and on whom.
 */
final class LogAccessChange
{
    public function handle(RoleAttachedEvent|RoleDetachedEvent|PermissionAttachedEvent|PermissionDetachedEvent $event): void
    {
        [$key, $event_name, $values] = match (true) {
            $event instanceof RoleAttachedEvent => ['roles', 'role_attached', $this->roleNames($event->rolesOrIds)],
            $event instanceof RoleDetachedEvent => ['roles', 'role_detached', $this->roleNames($event->rolesOrIds)],
            $event instanceof PermissionAttachedEvent => ['permissions', 'permission_attached', $this->permissionNames($event->permissionsOrIds)],
            $event instanceof PermissionDetachedEvent => ['permissions', 'permission_detached', $this->permissionNames($event->permissionsOrIds)],
        };

        Audit::record(
            log: 'iam',
            event: $event_name,
            description: str_replace('_', ' ', $event_name),
            causer: auth()->user(),
            subject: $event->model,
            properties: [$key => $values],
        );
    }

    /**
     * @return list<string>
     */
    private function roleNames(mixed $rolesOrIds): array
    {
        return Collection::wrap($rolesOrIds)
            ->map(static fn (mixed $item): string => $item instanceof RoleContract
                ? (string) $item->name
                : (Role::query()->find($item)?->name ?? (string) $item))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function permissionNames(mixed $permissionsOrIds): array
    {
        return Collection::wrap($permissionsOrIds)
            ->map(static fn (mixed $item): string => $item instanceof PermissionContract
                ? (string) $item->name
                : (Permission::query()->find($item)?->name ?? (string) $item))
            ->values()
            ->all();
    }
}
