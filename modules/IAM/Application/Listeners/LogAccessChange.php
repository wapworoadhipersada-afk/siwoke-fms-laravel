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
     * @return array<int, string>
     */
    private function roleNames(mixed $rolesOrIds): array
    {
        return Collection::wrap($rolesOrIds)
            ->map(static function (mixed $item): string {
                if ($item instanceof RoleContract) {
                    return (string) $item->name;
                }

                $role = is_int($item) || is_string($item) ? Role::query()->find($item) : null;

                return $role instanceof Role ? $role->name : (is_scalar($item) ? (string) $item : '');
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function permissionNames(mixed $permissionsOrIds): array
    {
        return Collection::wrap($permissionsOrIds)
            ->map(static function (mixed $item): string {
                if ($item instanceof PermissionContract) {
                    return (string) $item->name;
                }

                $permission = is_int($item) || is_string($item) ? Permission::query()->find($item) : null;

                return $permission instanceof Permission ? $permission->name : (is_scalar($item) ? (string) $item : '');
            })
            ->values()
            ->all();
    }
}
