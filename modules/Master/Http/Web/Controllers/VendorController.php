<?php

declare(strict_types=1);

namespace Modules\Master\Http\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Application\Actions\CreateVendor;
use Modules\Master\Application\Actions\DeleteVendor;
use Modules\Master\Application\Actions\UpdateVendor;
use Modules\Master\Domain\Models\Vendor;
use Modules\Master\Http\Web\Requests\VendorRequest;

final class VendorController extends MasterController
{
    public function index(Request $request): Response
    {
        $this->allow(Permissions::MASTER_VENDOR_VIEW);

        $search = trim((string) $request->query('q'));

        $vendors = Vendor::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%")))
            ->withCount(['merchants', 'terminals'])
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Vendor $vendor): array => [
                'ulid' => $vendor->ulid,
                'code' => $vendor->code,
                'name' => $vendor->name,
                'is_active' => $vendor->is_active,
                'merchants_count' => $vendor->getAttribute('merchants_count'),
                'terminals_count' => $vendor->getAttribute('terminals_count'),
            ]);

        return Inertia::render('master/vendors/Index', [
            'vendors' => $vendors,
            'filters' => ['q' => $search],
            'can' => ['manage' => $this->user()->can(Permissions::MASTER_VENDOR_MANAGE)],
        ]);
    }

    public function create(): Response
    {
        $this->allow(Permissions::MASTER_VENDOR_MANAGE);

        return Inertia::render('master/vendors/Form', ['vendor' => null]);
    }

    public function store(VendorRequest $request, CreateVendor $action): RedirectResponse
    {
        /** @var array{code: string, name: string, is_active: bool} $data */
        $data = $request->validated();

        $action->handle($data);
        $this->success('Vendor ditambahkan.');

        return to_route('master.vendors.index');
    }

    public function edit(Vendor $vendor): Response
    {
        $this->allow(Permissions::MASTER_VENDOR_MANAGE);

        return Inertia::render('master/vendors/Form', ['vendor' => [
            'ulid' => $vendor->ulid,
            'code' => $vendor->code,
            'name' => $vendor->name,
            'is_active' => $vendor->is_active,
        ]]);
    }

    public function update(VendorRequest $request, Vendor $vendor, UpdateVendor $action): RedirectResponse
    {
        /** @var array{code: string, name: string, is_active: bool} $data */
        $data = $request->validated();

        $action->handle($vendor, $data);
        $this->success('Vendor diperbarui.');

        return to_route('master.vendors.index');
    }

    public function destroy(Vendor $vendor, DeleteVendor $action): RedirectResponse
    {
        $this->allow(Permissions::MASTER_VENDOR_MANAGE);

        try {
            $action->handle($vendor);
            $this->success('Vendor dihapus.');
        } catch (ValidationException $exception) {
            $this->refusal($exception);
        }

        return to_route('master.vendors.index');
    }
}
