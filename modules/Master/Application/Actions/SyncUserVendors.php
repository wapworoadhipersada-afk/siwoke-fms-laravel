<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Master\Application\Services\VendorAccess;
use Modules\Master\Domain\Models\Vendor;
use Modules\Shared\Support\Audit;

/**
 * Replaces the vendors a person works for. An empty list means "not limited by vendor".
 */
final class SyncUserVendors
{
    public function __construct(private readonly VendorAccess $access) {}

    /**
     * @param  list<int>  $vendorIds
     */
    public function handle(User $user, array $vendorIds, ?User $actor = null): void
    {
        $vendorIds = array_values(array_unique($vendorIds));

        if (Vendor::query()->whereIn('id', $vendorIds)->count() !== count($vendorIds)) {
            throw ValidationException::withMessages(['vendor_ids' => 'Ada vendor yang tidak ditemukan.']);
        }

        DB::transaction(function () use ($user, $vendorIds, $actor): void {
            $before = DB::table('user_vendors')->where('user_id', $user->id)->orderBy('vendor_id')->pluck('vendor_id')->all();

            DB::table('user_vendors')->where('user_id', $user->id)->delete();

            if ($vendorIds !== []) {
                DB::table('user_vendors')->insert(array_map(static fn (int $vendorId): array => ['user_id' => $user->id, 'vendor_id' => $vendorId], $vendorIds));
            }

            sort($vendorIds);

            Audit::record('iam', 'vendor_scope_changed', 'Vendor pengguna diubah', $actor ?? auth()->user(), $user, ['before' => $before, 'after' => $vendorIds]);
        });

        $this->access->forget($user);
    }
}
