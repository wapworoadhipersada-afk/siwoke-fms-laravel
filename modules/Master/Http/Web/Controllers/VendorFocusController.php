<?php

declare(strict_types=1);

namespace Modules\Master\Http\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Master\Application\Services\VendorAccess;
use Modules\Master\Domain\Models\Vendor;

/**
 * The vendor switcher in the header: "all vendors" or one of the vendors this person works for. It only
 * narrows what lists and totals show, for this browser session; it never grants or removes any access.
 */
final class VendorFocusController extends MasterController
{
    public function update(Request $request, VendorAccess $access): RedirectResponse
    {
        $data = $request->validate(['vendor' => ['nullable', 'string', 'size:26']]);
        $user = $this->user();

        if (($data['vendor'] ?? '') === '') {
            $access->setFocus($user, null);
        } else {
            $vendor = Vendor::query()->where('ulid', (string) $data['vendor'])->where('is_active', true)->first();

            // A vendor the person may not use is simply not an option; the focus stays as it was.
            if ($vendor instanceof Vendor && $access->mayUse($user, $vendor->id)) {
                $access->setFocus($user, $vendor->id);
            }
        }

        // Back to where they were, but never to a page outside this application.
        return back(fallback: route('dashboard'));
    }
}
