<?php

declare(strict_types=1);

namespace Modules\Master\Http\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Application\Actions\CreateMerchant;
use Modules\Master\Application\Actions\DeleteMerchant;
use Modules\Master\Application\Actions\UpdateMerchant;
use Modules\Master\Application\Services\VendorAccess;
use Modules\Master\Domain\Models\Merchant;
use Modules\Master\Domain\Models\MerchantSite;
use Modules\Master\Domain\Models\Terminal;
use Modules\Master\Domain\Models\Vendor;
use Modules\Master\Http\Web\Requests\MerchantRequest;

final class MerchantController extends MasterController
{
    public function index(Request $request): Response
    {
        $this->allow(Permissions::MASTER_MERCHANT_VIEW);

        $search = trim((string) $request->query('q'));
        $vendorUlid = (string) $request->query('vendor');

        $merchants = Merchant::query()
            ->visibleTo($this->user())
            ->tap(fn ($focused) => app(VendorAccess::class)->focus($focused, $this->user(), 'merchants.vendor_id'))
            ->with('vendor')
            ->withCount('sites')
            ->when($vendorUlid !== '', fn ($query) => $query->whereHas('vendor', fn ($query) => $query->where('ulid', $vendorUlid)))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('merchant_code', 'ilike', "%{$search}%")))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Merchant $merchant): array => [
                'ulid' => $merchant->ulid,
                'merchant_code' => $merchant->merchant_code,
                'name' => $merchant->name,
                'category' => $merchant->category,
                'vendor' => $merchant->vendor->code,
                'sites_count' => $merchant->getAttribute('sites_count'),
            ]);

        return Inertia::render('master/merchants/Index', [
            'merchants' => $merchants,
            'filters' => ['q' => $search, 'vendor' => $vendorUlid],
            'vendors' => $this->vendorOptions(),
            'can' => ['manage' => $this->user()->can(Permissions::MASTER_MERCHANT_MANAGE)],
        ]);
    }

    public function create(): Response
    {
        $this->allow(Permissions::MASTER_MERCHANT_MANAGE);

        return Inertia::render('master/merchants/Form', [
            'merchant' => null,
            'vendors' => $this->vendorOptions(activeOnly: true),
        ]);
    }

    public function store(MerchantRequest $request, CreateMerchant $action): RedirectResponse
    {
        $data = $request->validated();

        $vendor = Vendor::query()->where('ulid', (string) $data['vendor'])->first();

        if (! $vendor instanceof Vendor) {
            throw ValidationException::withMessages(['vendor' => 'Vendor tidak ditemukan.']);
        }

        $merchant = $this->translatingErrors(['vendor_id' => 'vendor'], fn () => $action->handle([
            'vendor_id' => $vendor->id,
            'merchant_code' => (string) $data['merchant_code'],
            'name' => (string) $data['name'],
            'owner_name' => $data['owner_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'category' => $data['category'] ?? null,
        ]));

        $this->success('Merchant ditambahkan. Tambahkan site agar TID dapat didaftarkan.');

        return to_route('master.merchants.show', $merchant);
    }

    public function show(Merchant $merchant): Response
    {
        $this->allow(Permissions::MASTER_MERCHANT_VIEW);
        $this->visible($merchant);

        $user = $this->user();

        $sites = MerchantSite::query()
            ->visibleTo($user)
            ->where('merchant_id', $merchant->id)
            ->with(['location.parent.parent', 'terminals'])
            ->orderBy('id')
            ->get()
            ->map(fn (MerchantSite $site): array => [
                'ulid' => $site->ulid,
                'name' => $site->name,
                'address' => $site->address,
                'area' => $site->location->name,
                'service_point' => $site->location->parent?->name,
                'province' => $site->location->parent?->parent?->name,
                'latitude' => $site->latitude,
                'longitude' => $site->longitude,
                'pic_name' => $site->pic_name,
                'pic_phone' => $site->pic_phone,
                'is_active' => $site->is_active,
                'terminals' => $site->terminals->map(fn (Terminal $terminal): array => [
                    'ulid' => $terminal->ulid,
                    'tid' => $terminal->tid,
                    'status' => $terminal->status->value,
                    'status_label' => $terminal->status->label(),
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return Inertia::render('master/merchants/Show', [
            'merchant' => [
                'ulid' => $merchant->ulid,
                'merchant_code' => $merchant->merchant_code,
                'name' => $merchant->name,
                'owner_name' => $merchant->owner_name,
                'phone' => $merchant->phone,
                'category' => $merchant->category,
                'vendor' => $merchant->vendor->name,
            ],
            'sites' => $sites,
            'can' => [
                'manage' => $user->can(Permissions::MASTER_MERCHANT_MANAGE),
                'manageTerminals' => $user->can(Permissions::MASTER_TERMINAL_MANAGE),
            ],
        ]);
    }

    public function edit(Merchant $merchant): Response
    {
        $this->allow(Permissions::MASTER_MERCHANT_MANAGE);
        $this->visible($merchant);

        return Inertia::render('master/merchants/Form', [
            'merchant' => [
                'ulid' => $merchant->ulid,
                'vendor' => $merchant->vendor->name,
                'merchant_code' => $merchant->merchant_code,
                'name' => $merchant->name,
                'owner_name' => $merchant->owner_name,
                'phone' => $merchant->phone,
                'category' => $merchant->category,
            ],
            'vendors' => [],
        ]);
    }

    public function update(MerchantRequest $request, Merchant $merchant, UpdateMerchant $action): RedirectResponse
    {
        $this->visible($merchant);

        $data = $request->validated();

        $action->handle($merchant, [
            'merchant_code' => (string) $data['merchant_code'],
            'name' => (string) $data['name'],
            'owner_name' => $data['owner_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'category' => $data['category'] ?? null,
        ]);

        $this->success('Merchant diperbarui.');

        return to_route('master.merchants.show', $merchant);
    }

    public function destroy(Merchant $merchant, DeleteMerchant $action): RedirectResponse
    {
        $this->allow(Permissions::MASTER_MERCHANT_MANAGE);
        $this->visible($merchant);

        try {
            $action->handle($merchant);
            $this->success('Merchant dihapus.');
        } catch (ValidationException $exception) {
            $this->refusal($exception);

            return to_route('master.merchants.show', $merchant);
        }

        return to_route('master.merchants.index');
    }

    private function visible(Merchant $merchant): void
    {
        abort_unless(Merchant::query()->visibleTo($this->user())->whereKey($merchant->id)->exists(), 404);
    }

    /**
     * @return array<int, array{ulid: string, code: string, name: string}>
     */
    private function vendorOptions(bool $activeOnly = false): array
    {
        return Vendor::query()
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get()
            ->map(fn (Vendor $vendor): array => ['ulid' => $vendor->ulid, 'code' => $vendor->code, 'name' => $vendor->name])
            ->values()
            ->all();
    }
}
