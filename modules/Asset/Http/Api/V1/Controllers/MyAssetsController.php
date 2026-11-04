<?php

declare(strict_types=1);

namespace Modules\Asset\Http\Api\V1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetStock;
use Modules\IAM\Domain\Permissions;

final class MyAssetsController extends Controller
{
    /**
     * What the signed-in technician is holding right now: units and consumable balances.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        abort_unless($user->can(Permissions::ASSET_VIEW), 403);

        $assets = Asset::query()
            ->with(['model.category', 'vendor'])
            ->where('holder_type', HolderType::User->value)
            ->where('holder_id', $user->id)
            ->whereIn('status', [AssetStatus::WithTechnician->value, AssetStatus::InTransit->value])
            ->orderBy('asset_model_id')
            ->orderBy('serial_number')
            ->get()
            ->map(fn (Asset $asset): array => [
                'id' => $asset->ulid,
                'serial_number' => $asset->serial_number,
                'asset_tag' => $asset->asset_tag,
                'category' => $asset->model->category->code,
                'model' => $asset->model->displayName(),
                'vendor' => $asset->vendor->code,
                'status' => $asset->status->value,
                'condition' => $asset->condition->value,
            ])->values()->all();

        $stock = AssetStock::query()
            ->with(['model.category', 'vendor'])
            ->where('holder_type', HolderType::User->value)
            ->where('holder_id', $user->id)
            ->where('quantity', '>', 0)
            ->get()
            ->map(fn (AssetStock $balance): array => [
                'category' => $balance->model->category->code,
                'model' => $balance->model->displayName(),
                'vendor' => $balance->vendor->code,
                'quantity' => $balance->quantity,
            ])->values()->all();

        return response()->json(['data' => ['assets' => $assets, 'stock' => $stock]]);
    }
}
