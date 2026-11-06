<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Modules\IAM\Application\Services\BreadcrumbTrail;
use Modules\IAM\Application\Services\NavigationMenuService;
use Modules\Master\Application\Services\VendorAccess;
use Modules\Master\Domain\Models\Vendor;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // Names only, so the UI can hide what the user may not do. The server still enforces every rule.
                'permissions' => $request->user()?->getAllPermissions()->pluck('name')->values()->all() ?? [],
            ],
            // The sidebar, already filtered for this person's roles and permissions (see NavigationMenuService).
            'navigation' => fn (): array => $request->user() instanceof User ? app(NavigationMenuService::class)->forUser($request->user()) : [],
            // The vendor switcher in the header: what the person may choose from, and what is chosen now.
            'vendorFocus' => fn (): array => $request->user() instanceof User ? $this->vendorFocus($request->user()) : ['current' => null, 'options' => []],
            // Where this page sits, built from the sidebar and the route name (see BreadcrumbTrail).
            'breadcrumbs' => fn (): array => $request->user() instanceof User ? app(BreadcrumbTrail::class)->forRoute($request->route()) : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array{current: string|null, options: array<int, array{ulid: string, code: string, name: string}>}
     */
    private function vendorFocus(User $user): array
    {
        $access = app(VendorAccess::class);
        $ids = $access->selectableIds($user);
        $vendors = Vendor::query()->whereIn('id', $ids)->orderBy('name')->get();
        $focused = $access->focusedId($user);

        return [
            'current' => $focused === null ? null : $vendors->firstWhere('id', $focused)?->ulid,
            'options' => $vendors->map(fn (Vendor $vendor): array => ['ulid' => $vendor->ulid, 'code' => $vendor->code, 'name' => $vendor->name])->values()->all(),
        ];
    }
}
