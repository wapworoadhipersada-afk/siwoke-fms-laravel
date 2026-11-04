<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Master\Domain\Models\Location;
use Modules\Shared\Support\Audit;

/**
 * Replaces the subtrees a user is allowed to work in. Assigning a province covers every city,
 * area and service point beneath it.
 */
final class SyncUserLocationScopes
{
    /**
     * @param  list<int>  $locationIds
     */
    public function handle(User $user, array $locationIds): void
    {
        $locationIds = array_values(array_unique($locationIds));

        if (Location::query()->whereIn('id', $locationIds)->count() !== count($locationIds)) {
            throw ValidationException::withMessages(['location_ids' => 'Ada lokasi yang tidak ditemukan.']);
        }

        DB::transaction(function () use ($user, $locationIds): void {
            $before = DB::table('user_location_scopes')->where('user_id', $user->id)->orderBy('location_id')->pluck('location_id')->all();

            DB::table('user_location_scopes')->where('user_id', $user->id)->delete();
            DB::table('user_location_scopes')->insert(array_map(
                static fn (int $locationId): array => ['user_id' => $user->id, 'location_id' => $locationId],
                $locationIds,
            ));

            sort($locationIds);

            Audit::record('iam', 'location_scope_changed', 'Scope lokasi user diubah', auth()->user(), $user, [
                'before' => $before,
                'after' => $locationIds,
            ]);
        });
    }
}
