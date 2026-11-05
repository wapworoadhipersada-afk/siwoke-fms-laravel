<?php

declare(strict_types=1);

namespace Modules\Report\Application\Queries;

use App\Models\User;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Asset\Domain\Models\AssetStock;

/**
 * Asset figures for the dashboard, limited to the holders (warehouses, technicians, terminals) the user may see.
 */
final class AssetStatistics
{
    /**
     * @return array{by_status: list<array{status: string, label: string, total: int}>, stock: array<int, array{model: string, quantity: int}>}
     */
    public function handle(User $user): array
    {
        $counts = Asset::query()->visibleTo($user)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $byStatus = [];

        foreach (AssetStatus::cases() as $status) {
            $byStatus[] = ['status' => $status->value, 'label' => $status->label(), 'total' => (int) ($counts[$status->value] ?? 0)];
        }

        $stock = AssetStock::query()->visibleTo($user)
            ->selectRaw('asset_model_id, sum(quantity) as total')
            ->groupBy('asset_model_id')
            ->havingRaw('sum(quantity) > 0')
            ->orderByDesc('total')
            ->limit(8)
            ->pluck('total', 'asset_model_id');

        $models = AssetModel::query()->whereIn('id', $stock->keys())->get()->keyBy('id');

        return [
            'by_status' => $byStatus,
            'stock' => $stock->map(fn (mixed $total, mixed $modelId): array => [
                'model' => $models->get($modelId)?->displayName() ?? '-',
                'quantity' => (int) $total,
            ])->values()->all(),
        ];
    }
}
