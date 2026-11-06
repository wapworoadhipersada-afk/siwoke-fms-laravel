<?php

declare(strict_types=1);

namespace Modules\Master\Http\Web\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Domain\Models\Vendor;

/**
 * "Profil saya": who I am to the system, my code and picture, and exactly which vendors and areas I cover.
 * Read-only apart from the picture; coverage is changed by whoever manages users.
 */
final class MeController extends MasterController
{
    public function show(): Response
    {
        $user = $this->user();

        return Inertia::render('master/me/Show', [
            'me' => $this->profile($user),
            'vendors' => Vendor::query()->whereIn('id', DB::table('user_vendors')->where('user_id', $user->id)->pluck('vendor_id'))->orderBy('name')->get()
                ->map(fn (Vendor $vendor): array => ['code' => $vendor->code, 'name' => $vendor->name])->values()->all(),
            'areas' => Location::query()->with(['vendor', 'parent'])->whereIn('id', DB::table('user_location_scopes')->where('user_id', $user->id)->pluck('location_id'))->orderBy('path')->get()
                ->map(fn (Location $location): array => [
                    'name' => $location->name,
                    'type_label' => $location->type->label(),
                    'parent' => $location->parent?->name,
                    'vendor' => $location->vendor === null ? null : $location->vendor->code,
                ])->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        return [
            'ulid' => $user->ulid,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'user_code' => $user->user_code,
            'employee_no' => $user->employee_no,
            'user_type_label' => $user->user_type->label(),
            'roles' => $user->roles->map(fn ($role): string => (string) ($role->getAttribute('label') ?? $role->getAttribute('name')))->values()->all(),
            'photo_full_url' => $user->photo_path === null ? null : '/iam/users/'.$user->ulid.'/photo',
        ];
    }
}
