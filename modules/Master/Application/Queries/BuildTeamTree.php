<?php

declare(strict_types=1);

namespace Modules\Master\Application\Queries;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Master\Application\Services\VendorAccess;
use Modules\Master\Domain\Models\Vendor;

/**
 * The field organisation, per vendor: which coordinator holds which places, which leaders work under them, and
 * which engineers work under each leader.
 *
 * Nobody records "reports to" by hand. A person belongs under another when the areas they are assigned overlap
 * and they work for the same vendor, which is the same rule that decides what each of them can see.
 */
final class BuildTeamTree
{
    /**
     * @param  list<int>  $vendorIds  vendors to describe
     * @return list<array<string, mixed>>
     */
    public function handle(array $vendorIds): array
    {
        $areas = DB::table('locations as area')
            ->join('locations as sp', 'sp.id', '=', 'area.parent_id')
            ->where('area.type', 'area')
            ->whereNull('area.deleted_at')
            ->get(['area.id', 'area.path', 'sp.vendor_id as sp_vendor'])
            ->map(static fn (object $row): array => ['id' => (int) $row->id, 'path' => (string) $row->path, 'vendor' => $row->sp_vendor === null ? null : (int) $row->sp_vendor])
            ->all();

        /** @var array<int, list<array{path: string, name: string, type: string}>> $scopes */
        $scopes = [];

        foreach (DB::table('user_location_scopes as scope')->join('locations', 'locations.id', '=', 'scope.location_id')->get(['scope.user_id', 'locations.path', 'locations.name', 'locations.type']) as $row) {
            $scopes[(int) $row->user_id][] = ['path' => (string) $row->path, 'name' => (string) $row->name, 'type' => (string) $row->type];
        }

        $vendorAccess = app(VendorAccess::class);

        $people = [];

        foreach ([RoleName::Coordinator, RoleName::Leader, RoleName::Technician] as $role) {
            $people[$role->value] = User::query()->role($role->value)->where('is_active', true)->orderBy('name')->get();
        }

        $tree = [];

        foreach (Vendor::query()->whereIn('id', $vendorIds)->orderBy('name')->get() as $vendor) {
            $inVendor = static fn (array $area): bool => $area['vendor'] === null || $area['vendor'] === $vendor->id;
            $vendorAreas = array_values(array_filter($areas, $inVendor));

            // Areas a person is responsible for in this vendor; null when they do not work for the vendor at all.
            $coverage = function (User $user) use ($scopes, $vendorAreas, $vendorAccess, $vendor): ?array {
                if (! $vendorAccess->mayUse($user, $vendor->id)) {
                    return null;
                }

                $paths = array_column($scopes[$user->id] ?? [], 'path');

                return array_values(array_map(
                    static fn (array $area): int => $area['id'],
                    array_filter($vendorAreas, static fn (array $area): bool => array_filter($paths, static fn (string $path): bool => str_starts_with($area['path'], $path)) !== []),
                ));
            };

            $cover = [];

            foreach ($people as $users) {
                foreach ($users as $user) {
                    $areasOf = $coverage($user);

                    if ($areasOf !== null) {
                        $cover[$user->id] = $areasOf;
                    }
                }
            }

            $overlap = static fn (int $a, int $b): bool => array_intersect($cover[$a] ?? [], $cover[$b] ?? []) !== [];
            $card = static fn (User $user): array => [
                'ulid' => $user->ulid,
                'name' => $user->name,
                'user_code' => $user->user_code,
                'photo_url' => $user->photo_url,
                'area_count' => count($cover[$user->id] ?? []),
                'places' => array_column($scopes[$user->id] ?? [], 'name'),
            ];

            $coordinators = $people[RoleName::Coordinator->value]->filter(fn (User $user): bool => isset($cover[$user->id]) && $cover[$user->id] !== []);
            $leaders = $people[RoleName::Leader->value]->filter(fn (User $user): bool => isset($cover[$user->id]));
            $engineers = $people[RoleName::Technician->value]->filter(fn (User $user): bool => isset($cover[$user->id]));

            $placedLeaders = [];
            $placedEngineers = [];
            $groups = [];

            foreach ($coordinators as $coordinator) {
                $under = [];

                foreach ($leaders->filter(fn (User $leader): bool => $overlap($coordinator->id, $leader->id)) as $leader) {
                    $placedLeaders[$leader->id] = true;
                    $mine = [];

                    foreach ($engineers->filter(fn (User $engineer): bool => $overlap($leader->id, $engineer->id)) as $engineer) {
                        $placedEngineers[$engineer->id] = true;
                        $mine[] = $card($engineer);
                    }

                    $under[] = [...$card($leader), 'engineers' => $mine];
                }

                $groups[] = [...$card($coordinator), 'leaders' => $under];
            }

            // Leaders nobody above them covers still lead their engineers.
            $looseLeaders = [];

            foreach ($leaders->reject(fn (User $leader): bool => isset($placedLeaders[$leader->id])) as $leader) {
                $mine = [];

                foreach ($engineers->filter(fn (User $engineer): bool => $overlap($leader->id, $engineer->id)) as $engineer) {
                    $placedEngineers[$engineer->id] = true;
                    $mine[] = $card($engineer);
                }

                $looseLeaders[] = [...$card($leader), 'engineers' => $mine];
            }

            $tree[] = [
                'vendor' => ['code' => $vendor->code, 'name' => $vendor->name],
                'coordinators' => $groups,
                'loose_leaders' => $looseLeaders,
                'loose_engineers' => array_values($engineers->reject(fn (User $engineer): bool => isset($placedEngineers[$engineer->id]))->map($card)->all()),
            ];
        }

        return $tree;
    }
}
