<?php

declare(strict_types=1);

namespace Modules\Asset\Http\Web\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Asset\Application\Actions\AcceptHandover;
use Modules\Asset\Application\Actions\CancelHandover;
use Modules\Asset\Application\Actions\RejectHandover;
use Modules\Asset\Application\Actions\RequestHandover;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Application\Services\HandoverAuthority;
use Modules\Asset\Application\Services\HolderLabeler;
use Modules\Asset\Application\Services\StockBook;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Enums\HandoverStatus;
use Modules\Asset\Domain\Enums\HolderType;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetHandover;
use Modules\Asset\Domain\Models\AssetHandoverItem;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Asset\Domain\Models\AssetStock;
use Modules\Asset\Domain\Models\Warehouse;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Asset\Http\Web\Requests\IssueHandoverRequest;
use Modules\IAM\Domain\Enums\UserType;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Domain\Models\Vendor;
use Modules\Shared\Http\ModuleController;

final class HandoverController extends ModuleController
{
    public function __construct(
        private readonly HolderLabeler $labeler,
        private readonly HandoverAuthority $authority,
        private readonly StockBook $stock,
    ) {}

    public function index(Request $request): Response
    {
        $this->allow(Permissions::ASSET_VIEW);

        $user = $this->user();
        $status = HandoverStatus::tryFrom((string) $request->query('status', HandoverStatus::Pending->value));

        $page = AssetHandover::query()
            ->visibleTo($user)
            ->with(['items.asset', 'items.model', 'requester'])
            ->when($status instanceof HandoverStatus, fn ($query) => $query->where('status', $status?->value))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $holders = collect();

        foreach ($page->getCollection() as $handover) {
            $holders->push($handover->from(), $handover->to());
        }

        $labels = $this->labeler->labels($holders);

        return Inertia::render('asset/handovers/Index', [
            'handovers' => $page->through(fn (AssetHandover $handover): array => [
                'ulid' => $handover->ulid,
                'type_label' => $handover->type->label(),
                'status' => $handover->status->value,
                'status_label' => $handover->status->label(),
                'from' => $this->labeler->resolve($labels, $handover->from()),
                'to' => $this->labeler->resolve($labels, $handover->to()),
                'requested_by' => $handover->requester->name,
                'requested_at' => $handover->requested_at->toIso8601String(),
                'resolution_note' => $handover->resolution_note,
                'items' => $handover->items->map(fn (AssetHandoverItem $item): string => $item->asset instanceof Asset
                    ? "{$item->model->displayName()} · {$item->asset->serial_number}"
                    : "{$item->quantity} × {$item->model->displayName()}")->values()->all(),
                'can_resolve' => $handover->isPending() && $this->authority->canResolve($user, $handover),
                'can_cancel' => $handover->isPending() && $this->authority->canCancel($user, $handover),
            ]),
            'filters' => ['status' => $status instanceof HandoverStatus ? $status->value : ''],
            'statuses' => array_map(fn (HandoverStatus $case): array => ['value' => $case->value, 'label' => $case->label()], HandoverStatus::cases()),
            'can' => ['issue' => $user->can(Permissions::ASSET_TRANSFER)],
        ]);
    }

    /**
     * The form to hand assets from a warehouse to a technician. Picking a warehouse lists what it can give out.
     */
    public function create(Request $request): Response
    {
        $this->allow(Permissions::ASSET_TRANSFER);

        $user = $this->user();
        $warehouses = Warehouse::query()->visibleTo($user)->where('is_active', true)->orderBy('name')->get();
        $selected = $warehouses->firstWhere('ulid', (string) $request->query('warehouse'));

        $assets = [];
        $stocks = [];

        if ($selected instanceof Warehouse) {
            $assets = Asset::query()
                ->with(['model', 'vendor'])
                ->where('status', AssetStatus::InStock->value)
                ->where('holder_type', HolderType::Warehouse->value)
                ->where('holder_id', $selected->id)
                ->orderBy('asset_model_id')->orderBy('serial_number')
                ->limit(500)->get()
                ->map(fn (Asset $asset): array => [
                    'ulid' => $asset->ulid,
                    'label' => "{$asset->model->displayName()} · {$asset->serial_number}",
                    'vendor' => $asset->vendor->code,
                ])->values()->all();

            $stocks = AssetStock::query()
                ->with(['model', 'vendor'])
                ->where('holder_type', HolderType::Warehouse->value)
                ->where('holder_id', $selected->id)
                ->where('quantity', '>', 0)
                ->get()
                ->map(fn (AssetStock $stock): array => [
                    'model' => $stock->model->ulid,
                    'vendor' => $stock->vendor->ulid,
                    'label' => "{$stock->model->displayName()} ({$stock->vendor->code})",
                    'available' => $this->stock->available($stock->asset_model_id, $stock->owner_vendor_id, Holder::warehouse($selected)),
                ])->values()->all();
        }

        return Inertia::render('asset/handovers/Create', [
            'warehouses' => $warehouses->map(fn (Warehouse $warehouse): array => ['ulid' => $warehouse->ulid, 'label' => "{$warehouse->name} ({$warehouse->code})"])->values()->all(),
            'selected' => $selected?->ulid,
            'technicians' => User::query()->permission(Permissions::ASSET_TRANSFER_RECEIVE)->where('user_type', UserType::Technician->value)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (User $technician): array => ['ulid' => $technician->ulid, 'label' => $technician->name])->values()->all(),
            'assets' => $assets,
            'stocks' => $stocks,
        ]);
    }

    public function store(IssueHandoverRequest $request, RequestHandover $action): RedirectResponse
    {
        $data = $request->validated();
        $user = $this->user();

        $warehouse = Warehouse::query()->visibleTo($user)->where('ulid', (string) $data['warehouse'])->first();
        $technician = User::query()->where('ulid', (string) $data['technician'])->first();

        if (! $warehouse instanceof Warehouse) {
            throw ValidationException::withMessages(['warehouse' => 'Gudang tidak ditemukan.']);
        }

        if (! $technician instanceof User) {
            throw ValidationException::withMessages(['technician' => 'Teknisi tidak ditemukan.']);
        }

        $items = [];

        foreach (Asset::query()->whereIn('ulid', $data['assets'] ?? [])->get() as $asset) {
            $items[] = MoveItem::asset($asset);
        }

        foreach ($data['stock'] ?? [] as $line) {
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($quantity < 1) {
                continue;
            }

            $model = AssetModel::query()->where('ulid', (string) $line['model'])->first();
            $vendor = Vendor::query()->where('ulid', (string) $line['vendor'])->first();

            if (! $model instanceof AssetModel || ! $vendor instanceof Vendor) {
                throw ValidationException::withMessages(['stock' => 'Barang atau vendor tidak ditemukan.']);
            }

            $items[] = MoveItem::stock($model, $vendor, $quantity);
        }

        $this->translatingErrors(['items' => 'assets', 'quantity' => 'stock', 'to' => 'technician', 'from' => 'warehouse'], fn () => $action->handle(new MovementCommand(
            type: MovementType::IssueToTechnician,
            items: $items,
            from: Holder::warehouse($warehouse),
            to: Holder::user($technician),
            actor: $user,
            notes: $data['note'] ?? null,
        )));

        $this->success("Serah-terima ke {$technician->name} dikirim. Menunggu konfirmasi teknisi.");

        return to_route('asset.handovers.index');
    }

    public function accept(AssetHandover $handover, AcceptHandover $action): RedirectResponse
    {
        $this->allow(Permissions::ASSET_VIEW);
        $this->mustSee($handover);

        try {
            $action->handle($handover, $this->user());
            $this->success('Serah-terima diterima.');
        } catch (ValidationException $exception) {
            $this->refusal($exception);
        }

        return back();
    }

    public function reject(Request $request, AssetHandover $handover, RejectHandover $action): RedirectResponse
    {
        $this->allow(Permissions::ASSET_VIEW);
        $this->mustSee($handover);

        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        $action->handle($handover, $this->user(), $reason);
        $this->success('Serah-terima ditolak.');

        return back();
    }

    public function cancel(AssetHandover $handover, CancelHandover $action): RedirectResponse
    {
        $this->allow(Permissions::ASSET_VIEW);
        $this->mustSee($handover);

        try {
            $action->handle($handover, $this->user());
            $this->success('Serah-terima dibatalkan.');
        } catch (ValidationException $exception) {
            $this->refusal($exception);
        }

        return back();
    }

    private function mustSee(AssetHandover $handover): void
    {
        abort_unless(AssetHandover::query()->visibleTo($this->user())->whereKey($handover->id)->exists(), 404);
    }
}
