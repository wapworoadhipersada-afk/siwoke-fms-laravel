<?php

declare(strict_types=1);

namespace Modules\Master\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Domain\Models\Location;

/**
 * Decides which part of the location tree a user may work in.
 *
 * A user with `data.scope.all` (Super Admin, Managerial) sees everything. Everyone else sees
 * only the subtrees assigned to them, and a user with no assignment sees nothing: access is
 * granted explicitly, never by omission.
 */
final class LocationAccess
{
    public function isUnrestricted(User $user): bool
    {
        return $user->can(Permissions::DATA_SCOPE_ALL);
    }

    /**
     * Materialized paths of the subtrees assigned to the user.
     *
     * @return list<string>
     */
    public function rootPaths(User $user): array
    {
        $paths = DB::table('user_location_scopes as scope')
            ->join('locations', 'locations.id', '=', 'scope.location_id')
            ->where('scope.user_id', $user->id)
            ->whereNull('locations.deleted_at')
            ->pluck('locations.path')
            ->all();

        return array_values(array_map(static fn (mixed $path): string => (string) $path, $paths));
    }

    public function canAccess(User $user, Location $location): bool
    {
        if ($this->isUnrestricted($user)) {
            return true;
        }

        foreach ($this->rootPaths($user) as $path) {
            if (str_starts_with($location->path, $path)) {
                return true;
            }
        }

        return false;
    }
}
