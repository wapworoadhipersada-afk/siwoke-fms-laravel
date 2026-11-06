<?php

declare(strict_types=1);

namespace Modules\Master\Http\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Application\Actions\CreateMerchantSite;
use Modules\Master\Application\Actions\DeleteMerchantSite;
use Modules\Master\Application\Actions\UpdateMerchantSite;
use Modules\Master\Application\Services\LocationAccess;
use Modules\Master\Domain\Enums\LocationType;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Domain\Models\Merchant;
use Modules\Master\Domain\Models\MerchantSite;
use Modules\Master\Http\Web\Requests\MerchantSiteRequest;

final class MerchantSiteController extends MasterController
{
    public function __construct(private readonly LocationAccess $access) {}

    public function create(Merchant $merchant): Response
    {
        $this->allow(Permissions::MASTER_MERCHANT_MANAGE);
        $this->visibleMerchant($merchant);

        return Inertia::render('master/sites/Form', [
            'merchant' => $this->merchantSummary($merchant),
            'site' => null,
            'servicePoints' => $this->servicePointOptions($merchant->vendor_id),
        ]);
    }

    public function store(MerchantSiteRequest $request, Merchant $merchant, CreateMerchantSite $action): RedirectResponse
    {
        $this->visibleMerchant($merchant);

        $data = $request->validated();

        $this->translatingErrors(['location_id' => 'location'], fn () => $action->handle([
            'merchant_id' => $merchant->id,
            'location_id' => $this->resolveArea($data['location'])->id,
            ...$this->attributes($data),
        ]));

        $this->success('Site ditambahkan.');

        return to_route('master.merchants.show', $merchant);
    }

    public function edit(MerchantSite $site): Response
    {
        $this->allow(Permissions::MASTER_MERCHANT_MANAGE);
        $this->visibleSite($site);

        return Inertia::render('master/sites/Form', [
            'merchant' => $this->merchantSummary($site->merchant),
            'site' => [
                'ulid' => $site->ulid,
                'location' => $site->location->ulid,
                'name' => $site->name,
                'address' => $site->address,
                'latitude' => $site->latitude,
                'longitude' => $site->longitude,
                'pic_name' => $site->pic_name,
                'pic_phone' => $site->pic_phone,
                'is_active' => $site->is_active,
            ],
            'servicePoints' => $this->servicePointOptions($site->merchant->vendor_id),
        ]);
    }

    public function update(MerchantSiteRequest $request, MerchantSite $site, UpdateMerchantSite $action): RedirectResponse
    {
        $this->visibleSite($site);

        $data = $request->validated();

        $this->translatingErrors(['location_id' => 'location'], fn () => $action->handle($site, [
            'location_id' => $this->resolveArea($data['location'])->id,
            ...$this->attributes($data),
        ]));

        $this->success('Site diperbarui.');

        return to_route('master.merchants.show', $site->merchant);
    }

    public function destroy(MerchantSite $site, DeleteMerchantSite $action): RedirectResponse
    {
        $this->allow(Permissions::MASTER_MERCHANT_MANAGE);
        $this->visibleSite($site);

        $merchant = $site->merchant;

        try {
            $action->handle($site);
            $this->success('Site dihapus.');
        } catch (ValidationException $exception) {
            $this->refusal($exception);
        }

        return to_route('master.merchants.show', $merchant);
    }

    private function visibleMerchant(Merchant $merchant): void
    {
        abort_unless(Merchant::query()->visibleTo($this->user())->whereKey($merchant->id)->exists(), 404);
    }

    private function visibleSite(MerchantSite $site): void
    {
        abort_unless(MerchantSite::query()->visibleTo($this->user())->whereKey($site->id)->exists(), 404);
    }

    private function resolveArea(mixed $ulid): Location
    {
        $location = is_string($ulid) ? Location::query()->where('ulid', $ulid)->first() : null;

        if (! $location instanceof Location) {
            throw ValidationException::withMessages(['location' => 'Area Kelolaan tidak ditemukan.']);
        }

        abort_unless($this->access->canAccess($this->user(), $location), 403, 'Area Kelolaan berada di luar wilayah Anda.');

        return $location;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string|null, address: string, latitude: string|null, longitude: string|null, pic_name: string|null, pic_phone: string|null, is_active: bool}
     */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'] ?? null,
            'address' => (string) $data['address'],
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'pic_name' => $data['pic_name'] ?? null,
            'pic_phone' => $data['pic_phone'] ?? null,
            'is_active' => (bool) $data['is_active'],
        ];
    }

    /**
     * @return array{ulid: string, name: string, merchant_code: string}
     */
    private function merchantSummary(Merchant $merchant): array
    {
        return ['ulid' => $merchant->ulid, 'name' => $merchant->name, 'merchant_code' => $merchant->merchant_code];
    }

    /**
     * @return array<int, array{ulid: string, label: string}>
     */
    private function servicePointOptions(?int $vendorId = null): array
    {
        return Location::query()
            ->visibleTo($this->user())
            ->where('type', LocationType::Area->value)
            ->when($vendorId !== null, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('vendor_id')->orWhere('vendor_id', $vendorId)))
            ->with('parent')
            ->orderBy('name')
            ->get()
            ->map(fn (Location $location): array => [
                'ulid' => $location->ulid,
                'label' => "{$location->name} ({$location->code})".($location->parent instanceof Location ? " — {$location->parent->name}" : ''),
            ])
            ->values()
            ->all();
    }
}
