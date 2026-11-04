<?php

declare(strict_types=1);

namespace Modules\Master\Http\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Application\Actions\CreateLocation;
use Modules\Master\Application\Actions\DeleteLocation;
use Modules\Master\Application\Actions\UpdateLocation;
use Modules\Master\Application\Services\LocationAccess;
use Modules\Master\Domain\Enums\LocationType;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Http\Web\Requests\LocationRequest;

final class LocationController extends MasterController
{
    public function __construct(private readonly LocationAccess $access) {}

    public function index(Request $request): Response
    {
        $this->allow(Permissions::MASTER_LOCATION_VIEW);

        $search = trim((string) $request->query('q'));
        $type = LocationType::tryFrom((string) $request->query('type'));

        $locations = Location::query()
            ->visibleTo($this->user())
            ->with('parent')
            ->when($type instanceof LocationType, fn ($query) => $query->where('type', $type?->value))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%")))
            ->orderBy('depth')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Location $location): array => [
                'ulid' => $location->ulid,
                'type' => $location->type->value,
                'type_label' => $location->type->label(),
                'code' => $location->code,
                'name' => $location->name,
                'parent' => $location->parent?->name,
                'is_active' => $location->is_active,
            ]);

        return Inertia::render('master/locations/Index', [
            'locations' => $locations,
            'filters' => ['q' => $search, 'type' => $type instanceof LocationType ? $type->value : ''],
            'types' => $this->typeOptions(),
            'can' => ['manage' => $this->user()->can(Permissions::MASTER_LOCATION_MANAGE)],
        ]);
    }

    public function create(): Response
    {
        $this->allow(Permissions::MASTER_LOCATION_MANAGE);

        return Inertia::render('master/locations/Form', [
            'location' => null,
            'types' => $this->typeOptions(),
            'parentOptions' => $this->parentOptions(),
        ]);
    }

    public function store(LocationRequest $request, CreateLocation $action): RedirectResponse
    {
        $data = $request->validated();
        $type = LocationType::from((string) $data['type']);
        $parent = $this->resolveParent($type, $data['parent'] ?? null);

        $this->translatingErrors(['parent_id' => 'parent'], fn () => $action->handle([
            'type' => $type,
            'parent_id' => $parent?->id,
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'address' => $data['address'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'is_active' => (bool) $data['is_active'],
        ]));

        $this->success("{$type->label()} ditambahkan.");

        return to_route('master.locations.index');
    }

    public function edit(Location $location): Response
    {
        $this->allow(Permissions::MASTER_LOCATION_MANAGE);
        $this->visible($location);

        return Inertia::render('master/locations/Form', [
            'location' => [
                'ulid' => $location->ulid,
                'type' => $location->type->value,
                'type_label' => $location->type->label(),
                'parent' => $location->parent?->ulid,
                'code' => $location->code,
                'name' => $location->name,
                'address' => $location->address,
                'latitude' => $location->latitude,
                'longitude' => $location->longitude,
                'is_active' => $location->is_active,
            ],
            'types' => $this->typeOptions(),
            'parentOptions' => $this->parentOptions(),
        ]);
    }

    public function update(LocationRequest $request, Location $location, UpdateLocation $action): RedirectResponse
    {
        $this->visible($location);

        $data = $request->validated();
        $attributes = [
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'address' => $data['address'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'is_active' => (bool) $data['is_active'],
        ];

        if ($location->type->parentType() !== null) {
            $attributes['parent_id'] = $this->resolveParent($location->type, $data['parent'] ?? null)?->id;
        }

        $this->translatingErrors(['parent_id' => 'parent'], fn () => $action->handle($location, $attributes));
        $this->success('Lokasi diperbarui.');

        return to_route('master.locations.index');
    }

    public function destroy(Location $location, DeleteLocation $action): RedirectResponse
    {
        $this->allow(Permissions::MASTER_LOCATION_MANAGE);
        $this->visible($location);

        try {
            $action->handle($location);
            $this->success('Lokasi dihapus.');
        } catch (ValidationException $exception) {
            $this->refusal($exception);
        }

        return to_route('master.locations.index');
    }

    /**
     * Data outside the user's assigned areas does not exist as far as they are concerned.
     */
    private function visible(Location $location): void
    {
        abort_unless($this->access->canAccess($this->user(), $location), 404);
    }

    private function resolveParent(LocationType $type, mixed $ulid): ?Location
    {
        if (! is_string($ulid) || $ulid === '') {
            // Only unrestricted users may create a new root (province): it lies outside every assigned subtree.
            abort_if($type->parentType() === null && ! $this->access->isUnrestricted($this->user()), 403, 'Hanya pengguna dengan akses seluruh wilayah yang dapat membuat provinsi.');

            return null;
        }

        $parent = Location::query()->where('ulid', $ulid)->first();

        if (! $parent instanceof Location) {
            throw ValidationException::withMessages(['parent' => 'Lokasi induk tidak ditemukan.']);
        }

        abort_unless($this->access->canAccess($this->user(), $parent), 403, 'Lokasi induk berada di luar wilayah Anda.');

        return $parent;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(): array
    {
        return array_map(
            static fn (LocationType $type): array => ['value' => $type->value, 'label' => $type->label()],
            LocationType::cases(),
        );
    }

    /**
     * Candidate parents grouped by their own type, restricted to what the user can see.
     *
     * @return array<string, list<array{ulid: string, label: string}>>
     */
    private function parentOptions(): array
    {
        $options = [
            LocationType::Province->value => [],
            LocationType::City->value => [],
            LocationType::Area->value => [],
        ];

        $candidates = Location::query()
            ->visibleTo($this->user())
            ->whereIn('type', array_keys($options))
            ->with('parent')
            ->orderBy('name')
            ->get();

        foreach ($candidates as $candidate) {
            $options[$candidate->type->value][] = [
                'ulid' => $candidate->ulid,
                'label' => "{$candidate->name} ({$candidate->code})".($candidate->parent instanceof Location ? " — {$candidate->parent->name}" : ''),
            ];
        }

        return $options;
    }
}
