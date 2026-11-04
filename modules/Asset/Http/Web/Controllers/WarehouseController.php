<?php

declare(strict_types=1);

namespace Modules\Asset\Http\Web\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Asset\Application\Actions\CreateWarehouse;
use Modules\Asset\Application\Actions\DeleteWarehouse;
use Modules\Asset\Application\Actions\UpdateWarehouse;
use Modules\Asset\Domain\Models\Warehouse;
use Modules\Asset\Http\Web\Requests\WarehouseRequest;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Application\Services\LocationAccess;
use Modules\Master\Domain\Models\Location;
use Modules\Shared\Http\ModuleController;

final class WarehouseController extends ModuleController
{
    public function __construct(private readonly LocationAccess $access) {}

    public function index(Request $request): Response
    {
        $this->allow(Permissions::ASSET_VIEW);

        $search = trim((string) $request->query('q'));

        $warehouses = Warehouse::query()
            ->visibleTo($this->user())
            ->with(['location', 'pic'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%")))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Warehouse $warehouse): array => [
                'ulid' => $warehouse->ulid,
                'code' => $warehouse->code,
                'name' => $warehouse->name,
                'location' => $warehouse->location->name,
                'pic' => $warehouse->pic?->name,
                'is_active' => $warehouse->is_active,
            ]);

        return Inertia::render('asset/warehouses/Index', [
            'warehouses' => $warehouses,
            'filters' => ['q' => $search],
            'can' => ['manage' => $this->user()->can(Permissions::ASSET_CATALOG_MANAGE)],
        ]);
    }

    public function create(): Response
    {
        $this->allow(Permissions::ASSET_CATALOG_MANAGE);

        return Inertia::render('asset/warehouses/Form', [
            'warehouse' => null,
            'locations' => $this->locationOptions(),
            'people' => $this->personOptions(),
        ]);
    }

    public function store(WarehouseRequest $request, CreateWarehouse $action): RedirectResponse
    {
        $data = $request->validated();

        $this->translatingErrors(['location_id' => 'location', 'pic_user_id' => 'pic'], fn () => $action->handle([
            'location_id' => $this->resolveLocation($data['location'])->id,
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'pic_user_id' => $this->resolvePerson($data['pic'] ?? null)?->id,
            'is_active' => (bool) $data['is_active'],
        ]));

        $this->success('Gudang ditambahkan.');

        return to_route('asset.warehouses.index');
    }

    public function edit(Warehouse $warehouse): Response
    {
        $this->allow(Permissions::ASSET_CATALOG_MANAGE);
        $this->visible($warehouse);

        return Inertia::render('asset/warehouses/Form', [
            'warehouse' => [
                'ulid' => $warehouse->ulid,
                'location' => $warehouse->location->ulid,
                'code' => $warehouse->code,
                'name' => $warehouse->name,
                'pic' => $warehouse->pic?->ulid,
                'is_active' => $warehouse->is_active,
            ],
            'locations' => $this->locationOptions(),
            'people' => $this->personOptions(),
        ]);
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse, UpdateWarehouse $action): RedirectResponse
    {
        $this->visible($warehouse);

        $data = $request->validated();

        $this->translatingErrors(['location_id' => 'location', 'pic_user_id' => 'pic'], fn () => $action->handle($warehouse, [
            'location_id' => $this->resolveLocation($data['location'])->id,
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'pic_user_id' => $this->resolvePerson($data['pic'] ?? null)?->id,
            'is_active' => (bool) $data['is_active'],
        ]));

        $this->success('Gudang diperbarui.');

        return to_route('asset.warehouses.index');
    }

    public function destroy(Warehouse $warehouse, DeleteWarehouse $action): RedirectResponse
    {
        $this->allow(Permissions::ASSET_CATALOG_MANAGE);
        $this->visible($warehouse);

        try {
            $action->handle($warehouse);
            $this->success('Gudang dihapus.');
        } catch (ValidationException $exception) {
            $this->refusal($exception);
        }

        return to_route('asset.warehouses.index');
    }

    private function visible(Warehouse $warehouse): void
    {
        abort_unless(Warehouse::query()->visibleTo($this->user())->whereKey($warehouse->id)->exists(), 404);
    }

    private function resolveLocation(mixed $ulid): Location
    {
        $location = is_string($ulid) ? Location::query()->where('ulid', $ulid)->first() : null;

        if (! $location instanceof Location) {
            throw ValidationException::withMessages(['location' => 'Lokasi tidak ditemukan.']);
        }

        abort_unless($this->access->canAccess($this->user(), $location), 403, 'Lokasi berada di luar wilayah Anda.');

        return $location;
    }

    private function resolvePerson(mixed $ulid): ?User
    {
        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        $user = User::query()->where('ulid', $ulid)->first();

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['pic' => 'PIC tidak ditemukan.']);
        }

        return $user;
    }

    /**
     * @return array<int, array{ulid: string, label: string}>
     */
    private function locationOptions(): array
    {
        return Location::query()->visibleTo($this->user())->with('parent')->orderBy('depth')->orderBy('name')->get()
            ->map(fn (Location $location): array => [
                'ulid' => $location->ulid,
                'label' => "{$location->type->label()}: {$location->name}".($location->parent instanceof Location ? " — {$location->parent->name}" : ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{ulid: string, label: string}>
     */
    private function personOptions(): array
    {
        return User::query()->where('is_active', true)->orderBy('name')->limit(500)->get()
            ->map(fn (User $user): array => ['ulid' => $user->ulid, 'label' => "{$user->name} ({$user->email})"])
            ->values()
            ->all();
    }
}
