<?php

declare(strict_types=1);

namespace Modules\Master\Application\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Which vendors a person may work with, and which one they are looking at right now.
 *
 * Two different things, kept apart on purpose:
 *  - Access is a rule. A person with vendors assigned sees only those vendors, everywhere, no matter what
 *    they click. A person with none assigned is not limited by vendor (areas and roles still limit them).
 *  - Focus is a view. Someone who works for several vendors can pick one in the header and every list, total
 *    and report narrows to it. It never blocks opening a record by its address; it only filters lists.
 */
final class VendorAccess
{
    /**
     * @var array<int, list<int>|null>
     */
    private array $memo = [];

    /**
     * Vendor ids the person is limited to, or null when they are not limited.
     *
     * @return list<int>|null
     */
    public function allowedIds(User $user): ?array
    {
        if (! array_key_exists($user->id, $this->memo)) {
            $ids = DB::table('user_vendors')->where('user_id', $user->id)->pluck('vendor_id')->map(static fn (mixed $id): int => (int) $id)->all();

            $this->memo[$user->id] = $ids === [] ? null : array_values($ids);
        }

        return $this->memo[$user->id];
    }

    public function forget(User $user): void
    {
        unset($this->memo[$user->id]);
    }

    public function mayUse(User $user, int $vendorId): bool
    {
        $allowed = $this->allowedIds($user);

        return $allowed === null || in_array($vendorId, $allowed, true);
    }

    /**
     * The vendor chosen in the header, if it is one the person may use; otherwise null (all of theirs).
     */
    public function focusedId(User $user): ?int
    {
        if (! app()->bound('session') || ! request()->hasSession()) {
            return null;
        }

        $chosen = request()->session()->get('vendor_focus');

        return is_int($chosen) && $this->mayUse($user, $chosen) ? $chosen : null;
    }

    public function setFocus(User $user, ?int $vendorId): void
    {
        if ($vendorId !== null && ! $this->mayUse($user, $vendorId)) {
            return;
        }

        if ($vendorId === null) {
            request()->session()->forget('vendor_focus');

            return;
        }

        request()->session()->put('vendor_focus', $vendorId);
    }

    /**
     * Keeps a query to the vendors the person is allowed. This is the security rule, used by every visibleTo.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $user, string $column): Builder
    {
        $allowed = $this->allowedIds($user);

        return $allowed === null ? $query : $query->whereIn($column, $allowed);
    }

    /**
     * Narrows a list to the vendor in focus, if one is chosen. This is a view filter, not a permission.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function focus(Builder $query, User $user, string $column): Builder
    {
        $focused = $this->focusedId($user);

        return $focused === null ? $query : $query->where($column, $focused);
    }

    /**
     * Same, for a plain database query.
     */
    public function focusRaw(QueryBuilder $query, User $user, string $column): QueryBuilder
    {
        $focused = $this->focusedId($user);

        return $focused === null ? $query : $query->where($column, $focused);
    }

    /**
     * @return list<int> the vendors worth showing in the header switcher: everything allowed, or all vendors
     */
    public function selectableIds(User $user): array
    {
        $allowed = $this->allowedIds($user);

        return $allowed ?? array_values(array_map(static fn (mixed $id): int => (int) $id, DB::table('vendors')->whereNull('deleted_at')->where('is_active', true)->pluck('id')->all()));
    }
}
