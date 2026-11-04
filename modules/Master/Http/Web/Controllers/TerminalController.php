<?php

declare(strict_types=1);

namespace Modules\Master\Http\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Application\Actions\CreateTerminal;
use Modules\Master\Application\Actions\DeleteTerminal;
use Modules\Master\Application\Actions\UpdateTerminal;
use Modules\Master\Domain\Enums\TerminalStatus;
use Modules\Master\Domain\Models\MerchantSite;
use Modules\Master\Domain\Models\Terminal;
use Modules\Master\Domain\Models\Vendor;
use Modules\Master\Http\Web\Requests\TerminalRequest;

final class TerminalController extends MasterController
{
    public function index(Request $request): Response
    {
        $this->allow(Permissions::MASTER_TERMINAL_VIEW);

        $search = trim((string) $request->query('q'));
        $vendorUlid = (string) $request->query('vendor');
        $status = TerminalStatus::tryFrom((string) $request->query('status'));

        $terminals = Terminal::query()
            ->visibleTo($this->user())
            ->with(['vendor', 'merchant', 'site.location'])
            ->when($vendorUlid !== '', fn ($query) => $query->whereHas('vendor', fn ($query) => $query->where('ulid', $vendorUlid)))
            ->when($status instanceof TerminalStatus, fn ($query) => $query->where('status', $status?->value))
            ->when($search !== '', fn ($query) => $query->where('tid', 'ilike', "%{$search}%"))
            ->orderBy('tid')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Terminal $terminal): array => [
                'ulid' => $terminal->ulid,
                'tid' => $terminal->tid,
                'vendor' => $terminal->vendor->code,
                'merchant' => $terminal->merchant->name,
                'merchant_ulid' => $terminal->merchant->ulid,
                'address' => $terminal->site->address,
                'service_point' => $terminal->site->location->name,
                'status' => $terminal->status->value,
                'status_label' => $terminal->status->label(),
            ]);

        return Inertia::render('master/terminals/Index', [
            'terminals' => $terminals,
            'filters' => ['q' => $search, 'vendor' => $vendorUlid, 'status' => $status instanceof TerminalStatus ? $status->value : ''],
            'vendors' => Vendor::query()->orderBy('name')->get()
                ->map(fn (Vendor $vendor): array => ['ulid' => $vendor->ulid, 'code' => $vendor->code, 'name' => $vendor->name])
                ->values()->all(),
            'statuses' => $this->statusOptions(),
            'can' => ['manage' => $this->user()->can(Permissions::MASTER_TERMINAL_MANAGE)],
        ]);
    }

    /**
     * A TID is always registered from the site it will stand in.
     */
    public function create(Request $request): Response
    {
        $this->allow(Permissions::MASTER_TERMINAL_MANAGE);

        $site = $this->visibleSite((string) $request->query('site'));

        return Inertia::render('master/terminals/Form', [
            'terminal' => null,
            'site' => $this->siteSummary($site),
            'siteOptions' => [],
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function store(TerminalRequest $request, CreateTerminal $action): RedirectResponse
    {
        $data = $request->validated();
        $site = $this->visibleSite((string) $data['site']);

        $this->translatingErrors(['merchant_site_id' => 'site'], fn () => $action->handle([
            'merchant_site_id' => $site->id,
            'tid' => (string) $data['tid'],
            'status' => TerminalStatus::from((string) $data['status']),
        ]));

        $this->success('TID ditambahkan.');

        return to_route('master.merchants.show', $site->merchant);
    }

    public function edit(Terminal $terminal): Response
    {
        $this->allow(Permissions::MASTER_TERMINAL_MANAGE);
        $this->visible($terminal);

        $site = $terminal->site;

        return Inertia::render('master/terminals/Form', [
            'terminal' => [
                'ulid' => $terminal->ulid,
                'tid' => $terminal->tid,
                'status' => $terminal->status->value,
                'site' => $site->ulid,
            ],
            'site' => $this->siteSummary($site),
            // A TID can move between the sites of its own merchant here; moving to another merchant is not offered.
            'siteOptions' => MerchantSite::query()
                ->visibleTo($this->user())
                ->where('merchant_id', $terminal->merchant_id)
                ->orderBy('id')
                ->get()
                ->map(fn (MerchantSite $option): array => ['ulid' => $option->ulid, 'label' => $option->name ?? $option->address])
                ->values()
                ->all(),
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function update(TerminalRequest $request, Terminal $terminal, UpdateTerminal $action): RedirectResponse
    {
        $this->visible($terminal);

        $data = $request->validated();
        $site = $this->visibleSite((string) $data['site']);

        abort_unless($site->merchant_id === $terminal->merchant_id, 422, 'TID hanya dapat dipindahkan antar site milik merchant yang sama.');

        $this->translatingErrors(['merchant_site_id' => 'site'], fn () => $action->handle($terminal, [
            'merchant_site_id' => $site->id,
            'tid' => (string) $data['tid'],
            'status' => TerminalStatus::from((string) $data['status']),
        ]));

        $this->success('TID diperbarui.');

        return to_route('master.merchants.show', $site->merchant);
    }

    public function destroy(Terminal $terminal, DeleteTerminal $action): RedirectResponse
    {
        $this->allow(Permissions::MASTER_TERMINAL_MANAGE);
        $this->visible($terminal);

        $merchant = $terminal->merchant;

        try {
            $action->handle($terminal);
            $this->success('TID dihapus.');
        } catch (ValidationException $exception) {
            $this->refusal($exception);
        }

        return to_route('master.merchants.show', $merchant);
    }

    private function visible(Terminal $terminal): void
    {
        abort_unless(Terminal::query()->visibleTo($this->user())->whereKey($terminal->id)->exists(), 404);
    }

    private function visibleSite(string $ulid): MerchantSite
    {
        $site = MerchantSite::query()
            ->visibleTo($this->user())
            ->with('merchant.vendor')
            ->where('ulid', $ulid)
            ->first();

        abort_unless($site instanceof MerchantSite, 404);

        return $site;
    }

    /**
     * @return array{ulid: string, merchant: string, merchant_ulid: string, vendor: string, address: string}
     */
    private function siteSummary(MerchantSite $site): array
    {
        return [
            'ulid' => $site->ulid,
            'merchant' => $site->merchant->name,
            'merchant_ulid' => $site->merchant->ulid,
            'vendor' => $site->merchant->vendor->code,
            'address' => $site->address,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return array_map(
            static fn (TerminalStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
            TerminalStatus::cases(),
        );
    }
}
