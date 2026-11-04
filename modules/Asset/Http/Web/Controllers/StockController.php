<?php

declare(strict_types=1);

namespace Modules\Asset\Http\Web\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Asset\Application\Services\HolderLabeler;
use Modules\Asset\Domain\Models\AssetStock;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Domain\Models\Vendor;
use Modules\Shared\Http\ModuleController;

final class StockController extends ModuleController
{
    public function __construct(private readonly HolderLabeler $labeler) {}

    public function index(Request $request): Response
    {
        $this->allow(Permissions::ASSET_VIEW);

        $vendor = (string) $request->query('vendor');
        $search = trim((string) $request->query('q'));

        $page = AssetStock::query()
            ->visibleTo($this->user())
            ->with(['model', 'vendor'])
            ->where('quantity', '>', 0)
            ->when($vendor !== '', fn ($query) => $query->whereHas('vendor', fn ($query) => $query->where('ulid', $vendor)))
            ->when($search !== '', fn ($query) => $query->whereHas('model', fn ($query) => $query
                ->where('brand', 'ilike', "%{$search}%")
                ->orWhere('model_name', 'ilike', "%{$search}%")))
            ->orderBy('holder_type')
            ->orderBy('asset_model_id')
            ->paginate(20)
            ->withQueryString();

        $labels = $this->labeler->labels($page->getCollection()->map(fn (AssetStock $stock): Holder => new Holder($stock->holder_type, $stock->holder_id)));

        return Inertia::render('asset/stock/Index', [
            'stocks' => $page->through(fn (AssetStock $stock): array => [
                'id' => $stock->id,
                'model' => $stock->model->displayName(),
                'vendor' => $stock->vendor->code,
                'holder_type' => $stock->holder_type->label(),
                'holder' => $this->labeler->resolve($labels, new Holder($stock->holder_type, $stock->holder_id)),
                'quantity' => $stock->quantity,
            ]),
            'filters' => ['q' => $search, 'vendor' => $vendor],
            'vendors' => Vendor::query()->orderBy('name')->get()
                ->map(fn (Vendor $item): array => ['ulid' => $item->ulid, 'code' => $item->code, 'name' => $item->name])
                ->values()->all(),
        ]);
    }
}
