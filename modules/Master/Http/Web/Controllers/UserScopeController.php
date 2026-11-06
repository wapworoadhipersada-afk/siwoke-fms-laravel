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
use Modules\IAM\Domain\FieldAccount;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Application\Actions\SyncUserLocationScopes;
use Modules\Master\Application\Actions\SyncUserVendors;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Domain\Models\Vendor;

/**
 * What a person covers: the areas they work in and the vendors they work for. Both can be several. Someone
 * without any area sees no tickets, merchants or assets at all (unless their role sees every area), so this
 * is the screen that actually opens the data to them. No vendor means "not limited by vendor".
 */
final class UserScopeController extends MasterController
{
    public function edit(User $managedUser): Response
    {
        $this->allow(Permissions::IAM_USER_UPDATE);

        $field = FieldAccount::is($managedUser);

        return Inertia::render('master/user-scopes/Edit', [
            'backHref' => route($field ? 'iam.engineers.edit' : 'iam.users.edit', $managedUser->ulid, absolute: false),
            'managed' => [
                'ulid' => $managedUser->ulid,
                'name' => $managedUser->name,
                'email' => $managedUser->email,
                'user_code' => $managedUser->user_code,
                'photo_url' => $managedUser->photo_url,
                'roles' => $managedUser->roles->map(fn ($role): string => (string) ($role->getAttribute('label') ?? $role->getAttribute('name')))->values()->all(),
                'sees_everything' => $managedUser->can(Permissions::DATA_SCOPE_ALL),
            ],
            'locations' => Location::query()->with('vendor')->orderBy('path')->get()->map(fn (Location $location): array => [
                'id' => $location->id,
                'parent_id' => $location->parent_id,
                'name' => $location->name,
                'code' => $location->code,
                'type' => $location->type->value,
                'type_label' => $location->type->label(),
                'vendor' => $location->vendor === null ? null : $location->vendor->code,
            ])->values()->all(),
            'assigned' => DB::table('user_location_scopes')->where('user_id', $managedUser->id)->pluck('location_id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get()->map(fn (Vendor $vendor): array => ['ulid' => $vendor->ulid, 'code' => $vendor->code, 'label' => "{$vendor->code} — {$vendor->name}"])->values()->all(),
            'assignedVendors' => Vendor::query()->whereIn('id', DB::table('user_vendors')->where('user_id', $managedUser->id)->pluck('vendor_id'))->pluck('ulid')->values()->all(),
        ]);
    }

    public function update(Request $request, User $managedUser, SyncUserLocationScopes $areas, SyncUserVendors $vendors): RedirectResponse
    {
        $this->allow(Permissions::IAM_USER_UPDATE);

        /** @var array{location_ids?: list<int>|null, vendor_ids?: list<string>|null} $data */
        $data = $request->validate([
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer'],
            'vendor_ids' => ['nullable', 'array'],
            'vendor_ids.*' => ['string', 'size:26'],
        ]);

        try {
            $vendorIds = array_values(array_map(intval(...), Vendor::query()->whereIn('ulid', $data['vendor_ids'] ?? [])->pluck('id')->all()));

            if (count($vendorIds) !== count(array_unique($data['vendor_ids'] ?? []))) {
                throw ValidationException::withMessages(['vendor_ids' => 'Ada vendor yang tidak ditemukan.']);
            }

            $areas->handle($managedUser, array_values($data['location_ids'] ?? []));
            $vendors->handle($managedUser, $vendorIds, $this->user());
            $this->success('Vendor dan area disimpan.');
        } catch (ValidationException $exception) {
            $this->refusal($exception);
        }

        // "Simpan dan selesai" goes back to the list the person came from; "Simpan" stays to keep adjusting.
        if ($request->boolean('done')) {
            return to_route(FieldAccount::is($managedUser) ? 'iam.engineers.index' : 'iam.users.index');
        }

        return to_route('master.user-scopes.edit', $managedUser->ulid);
    }
}
