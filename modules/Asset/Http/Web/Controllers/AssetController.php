<?php

declare(strict_types=1);

namespace Modules\Asset\Http\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Asset\Application\Actions\MoveAssets;
use Modules\Asset\Application\Actions\ReceiveAssets;
use Modules\Asset\Application\Actions\UpdateAsset;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Application\Services\HolderLabeler;
use Modules\Asset\Domain\Enums\AssetCondition;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Enums\TrackingMode;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Asset\Domain\Models\AssetMovementItem;
use Modules\Asset\Domain\Models\Warehouse;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Asset\Http\Web\Requests\MoveAssetRequest;
use Modules\Asset\Http\Web\Requests\ReceiveAssetsRequest;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Domain\Models\Vendor;
use Modules\Shared\Http\ModuleController;

final class AssetController extends ModuleController
{
    public function __construct(private readonly HolderLabeler $labeler) {}

    public function index(Request $request): Response
    {
        $this->allow(Permissions::ASSET_VIEW);

        $search = trim((string) $request->query('q'));
        $status = AssetStatus::tryFrom((string) $request->query('status'));
        $vendor = (string) $request->query('vendor');
        $model = (string) $request->query('model');

        $page = Asset::query()
            ->visibleTo($this->user())
            ->with(['model.category', 'vendor'])
            ->when($status instanceof AssetStatus, fn ($query) => $query->where('assets.status', $status?->value))
            ->when($vendor !== '', fn ($query) => $query->whereHas('vendor', fn ($query) => $query->where('ulid', $vendor)))
            ->when($model !== '', fn ($query) => $query->whereHas('model', fn ($query) => $query->where('ulid', $model)))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('serial_number', 'ilike', "%{$search}%")
                ->orWhere('asset_tag', 'ilike', "%{$search}%")))
            ->orderByDesc('assets.id')
            ->paginate(15)
            ->withQueryString();

        $labels = $this->labeler->labels($page->getCollection()->map(fn (Asset $asset): Holder => $asset->holder()));

        $assets = $page->through(fn (Asset $asset): array => [
            'ulid' => $asset->ulid,
            'serial_number' => $asset->serial_number,
            'asset_tag' => $asset->asset_tag,
            'model' => $asset->model->displayName(),
            'vendor' => $asset->vendor->code,
            'status' => $asset->status->value,
            'status_label' => $asset->status->label(),
            'condition_label' => $asset->condition->label(),
            'holder' => $this->labeler->resolve($labels, $asset->holder()),
        ]);

        $user = $this->user();

        return Inertia::render('asset/assets/Index', [
            'assets' => $assets,
            'filters' => ['q' => $search, 'status' => $status instanceof AssetStatus ? $status->value : '', 'vendor' => $vendor, 'model' => $model],
            'statuses' => array_map(fn (AssetStatus $case): array => ['value' => $case->value, 'label' => $case->label()], AssetStatus::cases()),
            'vendors' => $this->vendorOptions(),
            'models' => $this->modelOptions(TrackingMode::Serialized),
            'can' => ['receive' => $user->can(Permissions::ASSET_CREATE)],
        ]);
    }

    public function create(): Response
    {
        $this->allow(Permissions::ASSET_CREATE);

        return Inertia::render('asset/assets/Receive', [
            'models' => $this->modelOptions(),
            'vendors' => $this->vendorOptions(activeOnly: true),
            'warehouses' => $this->warehouseOptions(),
            'conditions' => array_map(fn (AssetCondition $case): array => ['value' => $case->value, 'label' => $case->label()], AssetCondition::cases()),
        ]);
    }

    public function store(ReceiveAssetsRequest $request, ReceiveAssets $action): RedirectResponse
    {
        $data = $request->validated();

        $model = AssetModel::query()->with('category')->where('ulid', (string) $data['asset_model'])->first();
        $vendor = Vendor::query()->where('ulid', (string) $data['vendor'])->first();
        $warehouse = Warehouse::query()->visibleTo($this->user())->where('ulid', (string) $data['warehouse'])->first();

        foreach (['asset_model' => $model, 'vendor' => $vendor, 'warehouse' => $warehouse] as $field => $found) {
            if ($found === null) {
                throw ValidationException::withMessages([$field => 'Data tidak ditemukan.']);
            }
        }

        /** @var AssetModel $model */
        /** @var Vendor $vendor */
        /** @var Warehouse $warehouse */
        $serials = $model->category->tracking_mode === TrackingMode::Serialized
            ? array_values(array_filter(preg_split('/[\r\n,;]+/', (string) ($data['serials'] ?? '')) ?: [], static fn (string $serial): bool => trim($serial) !== ''))
            : [];

        $this->translatingErrors([], fn () => $action->handle(
            model: $model,
            vendor: $vendor,
            warehouse: $warehouse,
            actor: $this->user(),
            serials: $serials,
            quantity: (int) ($data['quantity'] ?? 0),
            notes: $data['notes'] ?? null,
            condition: AssetCondition::tryFrom((string) ($data['condition'] ?? '')) ?? AssetCondition::Good,
        ));

        $this->success($model->category->tracking_mode === TrackingMode::Serialized ? count($serials).' unit diterima ke '.$warehouse->name.'.' : 'Stok diterima ke '.$warehouse->name.'.');

        return to_route('asset.assets.index');
    }

    public function show(Asset $asset): Response
    {
        $this->allow(Permissions::ASSET_VIEW);
        $this->visible($asset);

        $asset->load(['model.category', 'vendor', 'pendingHandover']);

        $items = AssetMovementItem::query()
            ->where('asset_id', $asset->id)
            ->with('movement.actor')
            ->orderByDesc('movement_id')
            ->get();

        $holders = collect([$asset->holder()]);

        foreach ($items as $item) {
            $holders->push($item->movement->from(), $item->movement->to());
        }

        $labels = $this->labeler->labels($holders);
        $user = $this->user();

        return Inertia::render('asset/assets/Show', [
            'asset' => [
                'ulid' => $asset->ulid,
                'serial_number' => $asset->serial_number,
                'asset_tag' => $asset->asset_tag,
                'model' => $asset->model->displayName(),
                'category' => $asset->model->category->name,
                'vendor' => "{$asset->vendor->code} — {$asset->vendor->name}",
                'status' => $asset->status->value,
                'status_label' => $asset->status->label(),
                'condition' => $asset->condition->value,
                'condition_label' => $asset->condition->label(),
                'holder' => $this->labeler->resolve($labels, $asset->holder()),
                'pending_handover' => $asset->pendingHandover?->ulid,
            ],
            'history' => $items->map(function (AssetMovementItem $item) use ($labels): array {
                $movement = $item->movement;

                return [
                    'movement_no' => $movement->movement_no,
                    'type_label' => $movement->type->label(),
                    'from' => $this->labeler->resolve($labels, $movement->from()),
                    'to' => $this->labeler->resolve($labels, $movement->to()),
                    'actor' => $movement->actor->name,
                    'occurred_at' => $movement->occurred_at->toIso8601String(),
                    'notes' => $movement->notes,
                ];
            })->values()->all(),
            'conditions' => array_map(fn (AssetCondition $case): array => ['value' => $case->value, 'label' => $case->label()], AssetCondition::cases()),
            'warehouses' => $user->can(Permissions::ASSET_UPDATE) ? $this->warehouseOptions() : [],
            'can' => [
                'update' => $user->can(Permissions::ASSET_UPDATE),
                'approve' => $user->can(Permissions::ASSET_TRANSFER_APPROVE),
            ],
        ]);
    }

    public function update(Request $request, Asset $asset, UpdateAsset $action): RedirectResponse
    {
        $this->allow(Permissions::ASSET_UPDATE);
        $this->visible($asset);

        $data = $request->validate([
            'condition' => ['required', 'string', 'in:'.implode(',', array_map(fn (AssetCondition $case): string => $case->value, AssetCondition::cases()))],
            'asset_tag' => ['nullable', 'string', 'max:50'],
        ]);

        $action->handle($asset, AssetCondition::from($data['condition']), $data['asset_tag'] ?? null);
        $this->success('Aset diperbarui.');

        return back();
    }

    /**
     * Movements an office user records directly: repair, loss, retirement.
     */
    public function move(MoveAssetRequest $request, Asset $asset, MoveAssets $action): RedirectResponse
    {
        $this->visible($asset);

        $data = $request->validated();
        $type = MovementType::from((string) $data['type']);

        $this->allow($type === MovementType::SendToRepair || $type === MovementType::ReturnFromRepair
            ? Permissions::ASSET_UPDATE
            : Permissions::ASSET_TRANSFER_APPROVE);

        $to = Holder::external();

        if ($type === MovementType::ReturnFromRepair) {
            $warehouse = Warehouse::query()->visibleTo($this->user())->where('ulid', (string) ($data['warehouse'] ?? ''))->first();

            if (! $warehouse instanceof Warehouse) {
                throw ValidationException::withMessages(['warehouse' => 'Gudang tidak ditemukan.']);
            }

            $to = Holder::warehouse($warehouse);
        }

        // Rule failures all belong to the one action the person just chose.
        $this->translatingErrors(['items' => 'type', 'from' => 'type', 'to' => 'type'], fn () => $action->handle(new MovementCommand(
            type: $type,
            items: [MoveItem::asset($asset)],
            from: $asset->holder(),
            to: $to,
            actor: $this->user(),
            notes: $data['notes'] ?? null,
        )));

        $this->success("{$type->label()} dicatat.");

        return back();
    }

    private function visible(Asset $asset): void
    {
        abort_unless(Asset::query()->visibleTo($this->user())->whereKey($asset->id)->exists(), 404);
    }

    /**
     * @return array<int, array{ulid: string, code: string, name: string}>
     */
    private function vendorOptions(bool $activeOnly = false): array
    {
        return Vendor::query()
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')->get()
            ->map(fn (Vendor $vendor): array => ['ulid' => $vendor->ulid, 'code' => $vendor->code, 'name' => $vendor->name])
            ->values()->all();
    }

    /**
     * @return array<int, array{ulid: string, label: string, tracking: string}>
     */
    private function modelOptions(?TrackingMode $only = null): array
    {
        return AssetModel::query()->with('category')->where('is_active', true)->orderBy('brand')->orderBy('model_name')->get()
            ->filter(fn (AssetModel $model): bool => $only === null || $model->category->tracking_mode === $only)
            ->map(fn (AssetModel $model): array => [
                'ulid' => $model->ulid,
                'label' => "{$model->category->name}: {$model->displayName()}",
                'tracking' => $model->category->tracking_mode->value,
            ])
            ->values()->all();
    }

    /**
     * @return array<int, array{ulid: string, label: string}>
     */
    private function warehouseOptions(): array
    {
        return Warehouse::query()->visibleTo($this->user())->where('is_active', true)->orderBy('name')->get()
            ->map(fn (Warehouse $warehouse): array => ['ulid' => $warehouse->ulid, 'label' => "{$warehouse->name} ({$warehouse->code})"])
            ->values()->all();
    }
}
