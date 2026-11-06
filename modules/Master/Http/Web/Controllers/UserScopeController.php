<?php

declare(strict_types=1);

namespace Modules\Master\Http\Web\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Application\Actions\SyncUserLocationScopes;
use Modules\Master\Domain\Models\Location;

/**
 * Which areas a person works in. Someone without any area sees no tickets, merchants or assets at all,
 * unless their role sees every area, so this is the screen that actually opens the data to them.
 */
final class UserScopeController extends MasterController
{
    public function edit(User $managedUser): Response
    {
        $this->allow(Permissions::IAM_USER_UPDATE);

        return Inertia::render('master/user-scopes/Edit', [
            'managed' => ['ulid' => $managedUser->ulid, 'name' => $managedUser->name, 'email' => $managedUser->email, 'sees_everything' => $managedUser->can(Permissions::DATA_SCOPE_ALL)],
            'locations' => Location::query()->orderBy('path')->get()->map(fn (Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'type_label' => $location->type->label(),
                'depth' => $location->depth,
            ])->values()->all(),
            'assigned' => DB::table('user_location_scopes')->where('user_id', $managedUser->id)->pluck('location_id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
        ]);
    }

    public function update(Request $request, User $managedUser, SyncUserLocationScopes $action): RedirectResponse
    {
        $this->allow(Permissions::IAM_USER_UPDATE);

        /** @var array{location_ids?: list<int>|null} $data */
        $data = $request->validate(['location_ids' => ['nullable', 'array'], 'location_ids.*' => ['integer']]);

        try {
            $action->handle($managedUser, array_values($data['location_ids'] ?? []));
            $this->success('Area kerja disimpan.');
        } catch (ValidationException $exception) {
            $this->refusal($exception);
        }

        return to_route('master.user-scopes.edit', $managedUser->ulid);
    }
}
