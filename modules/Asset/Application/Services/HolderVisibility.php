<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Models\Warehouse;
use Modules\Master\Application\Services\LocationAccess;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Domain\Models\Terminal;

/**
 * Restricts anything that has a holder (assets, stock balances, handovers) to what the user may see.
 *
 * A custody is visible when it sits in a warehouse or at a terminal inside the user's areas, or with a
 * technician who works in those areas (or is the user). Assets outside the company's custody (in repair,
 * lost, retired) are visible only to users who see every area.
 */
final class HolderVisibility
{
    public function __construct(private readonly LocationAccess $access) {}

    public function isUnrestricted(User $user): bool
    {
        return $this->access->isUnrestricted($user);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $user, string $typeColumn, string $idColumn): Builder
    {
        if ($this->access->isUnrestricted($user)) {
            return $query;
        }

        return $query->where(fn (Builder $query) => $this->custodyClauses($query, $user, $typeColumn, $idColumn));
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public function custodyClauses(Builder $query, User $user, string $typeColumn, string $idColumn): void
    {
        $query->where(fn (Builder $query) => $query
            ->where($typeColumn, HolderType::Warehouse->value)
            ->whereIn($idColumn, Warehouse::query()->visibleTo($user)->select('warehouses.id')))
            ->orWhere(fn (Builder $query) => $query
                ->where($typeColumn, HolderType::Terminal->value)
                ->whereIn($idColumn, Terminal::query()->visibleTo($user)->select('terminals.id')))
            ->orWhere(fn (Builder $query) => $query
                ->where($typeColumn, HolderType::User->value)
                ->where(fn (Builder $query) => $query
                    ->where($idColumn, $user->id)
                    ->orWhereIn($idColumn, DB::table('user_location_scopes')
                        ->whereIn('location_id', Location::query()->visibleTo($user)->select('locations.id'))
                        ->select('user_id'))));
    }
}
