<?php

declare(strict_types=1);

namespace Modules\Master\Http\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Application\Actions\ImportMerchantDocument;
use Modules\Master\Application\Actions\SaveMerchantDocument;
use Modules\Master\Application\Services\MerchantSheetReader;
use Modules\Master\Application\Services\VendorAccess;
use Modules\Master\Domain\Models\MerchantDocument;
use Modules\Master\Domain\Models\MerchantDocumentLine;
use Modules\Master\Domain\Models\Vendor;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The documents the principal sends: a number, the date it arrived, and a list of merchants with their TIDs.
 * Lines come from an Excel/CSV file or are typed in; either way the document is checked first and imported
 * only when the person confirms, so merchants and TIDs enter the master data together, from one source.
 */
final class MerchantDocumentController extends MasterController
{
    public function index(Request $request): Response
    {
        $this->allow(Permissions::MASTER_TERMINAL_VIEW);

        $user = $this->user();
        $search = trim((string) $request->query('q'));
        $status = (string) $request->query('status');

        $documents = MerchantDocument::query()
            ->visibleTo($user)
            ->tap(fn ($focused) => app(VendorAccess::class)->focus($focused, $user, 'merchant_documents.vendor_id'))
            ->with('vendor')
            ->withCount([
                'lines',
                'lines as imported_count' => fn ($query) => $query->where('status', MerchantDocumentLine::IMPORTED),
                'lines as error_count' => fn ($query) => $query->where('status', MerchantDocumentLine::ERROR),
            ])
            ->when(in_array($status, [MerchantDocument::DRAFT, MerchantDocument::IMPORTED], true), fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where('document_number', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (MerchantDocument $document): array => [
                'ulid' => $document->ulid,
                'document_number' => $document->document_number,
                'vendor' => $document->vendor->code,
                'received_on' => $document->received_on->toDateString(),
                'status' => $document->status,
                'source' => $document->source,
                'lines' => (int) $document->getAttribute('lines_count'),
                'imported' => (int) $document->getAttribute('imported_count'),
                'errors' => (int) $document->getAttribute('error_count'),
            ]);

        return Inertia::render('master/documents/Index', [
            'documents' => $documents,
            'filters' => ['q' => $search, 'status' => $status],
            'can' => ['manage' => $user->can(Permissions::MASTER_TERMINAL_MANAGE)],
        ]);
    }

    public function create(): Response
    {
        $this->allow(Permissions::MASTER_TERMINAL_MANAGE);

        $access = app(VendorAccess::class);
        $user = $this->user();

        return Inertia::render('master/documents/Create', [
            'vendors' => Vendor::query()->whereIn('id', $access->selectableIds($user))->orderBy('name')->get()
                ->map(fn (Vendor $vendor): array => ['ulid' => $vendor->ulid, 'label' => "{$vendor->code} — {$vendor->name}"])->values()->all(),
            'defaultVendor' => ($focused = $access->focusedId($user)) === null ? null : Vendor::query()->whereKey($focused)->value('ulid'),
            'places' => [
                'provinces' => DB::table('locations')->where('type', 'province')->whereNull('deleted_at')->orderBy('name')->pluck('name')->all(),
                'service_points' => DB::table('locations')->where('type', 'service_point')->whereNull('deleted_at')->orderBy('name')->pluck('name')->all(),
                'areas' => DB::table('locations')->where('type', 'area')->whereNull('deleted_at')->orderBy('name')->pluck('name')->all(),
            ],
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, SaveMerchantDocument $action, MerchantSheetReader $reader): RedirectResponse
    {
        $this->allow(Permissions::MASTER_TERMINAL_MANAGE);

        $data = $request->validate([
            'vendor' => ['required', 'string', 'size:26'],
            'document_number' => ['required', 'string', 'max:120'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'max:5120'],
            'lines' => ['nullable', 'array', 'max:'.MerchantSheetReader::MAX_ROWS],
            'lines.*.merchant_name' => ['nullable', 'string', 'max:255'],
            'lines.*.tid' => ['nullable', 'string', 'max:40'],
            'lines.*.mid' => ['nullable', 'string', 'max:40'],
            'lines.*.address' => ['nullable', 'string', 'max:1000'],
            'lines.*.province' => ['nullable', 'string', 'max:255'],
            'lines.*.service_point' => ['nullable', 'string', 'max:255'],
            'lines.*.area' => ['nullable', 'string', 'max:255'],
            'lines.*.tid_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $vendor = Vendor::query()->where('ulid', (string) $data['vendor'])->first();

        if (! $vendor instanceof Vendor || ! app(VendorAccess::class)->mayUse($this->user(), $vendor->id)) {
            throw ValidationException::withMessages(['vendor' => 'Vendor tidak dikenal.']);
        }

        $file = $request->file('file');

        if (is_array($file)) {
            throw ValidationException::withMessages(['file' => 'Unggah satu file saja.']);
        }

        /** @var list<array<string, string|null>> $rows */
        $rows = $file !== null ? $reader->read($file) : array_values((array) ($data['lines'] ?? []));

        $document = $action->handle(
            $vendor,
            (string) $data['document_number'],
            (string) $data['received_on'],
            $data['notes'] ?? null,
            $rows,
            $this->user(),
            $file !== null ? 'upload' : 'manual',
            $file?->getClientOriginalName(),
        );

        $this->success('Dokumen dicatat sebagai draf. Periksa barisnya, lalu impor.');

        return to_route('master.documents.show', $document);
    }

    public function show(MerchantDocument $document): Response
    {
        $this->allow(Permissions::MASTER_TERMINAL_VIEW);
        $this->mustSee($document);

        $document->load('vendor', 'creator');

        $lines = $document->lines()->get();

        return Inertia::render('master/documents/Show', [
            'document' => [
                'ulid' => $document->ulid,
                'document_number' => $document->document_number,
                'vendor' => "{$document->vendor->code} — {$document->vendor->name}",
                'received_on' => $document->received_on->toDateString(),
                'notes' => $document->notes,
                'status' => $document->status,
                'source' => $document->source,
                'source_name' => $document->source_name,
                'created_by' => $document->creator?->name,
                'imported_at' => $document->imported_at?->toDateTimeString(),
            ],
            'lines' => $lines->map(fn (MerchantDocumentLine $line): array => [
                'line_no' => $line->line_no,
                'merchant_name' => $line->merchant_name,
                'tid' => $line->tid,
                'mid' => $line->mid,
                'address' => $line->address,
                'province' => $line->province,
                'service_point' => $line->service_point,
                'area' => $line->area,
                'tid_note' => $line->tid_note,
                'status' => $line->status,
                'error' => $line->error,
            ])->values()->all(),
            'counts' => [
                'pending' => $lines->where('status', MerchantDocumentLine::PENDING)->count(),
                'error' => $lines->where('status', MerchantDocumentLine::ERROR)->count(),
                'imported' => $lines->where('status', MerchantDocumentLine::IMPORTED)->count(),
            ],
            'can' => ['manage' => $this->user()->can(Permissions::MASTER_TERMINAL_MANAGE)],
        ]);
    }

    public function import(MerchantDocument $document, ImportMerchantDocument $action): RedirectResponse
    {
        $this->allow(Permissions::MASTER_TERMINAL_MANAGE);
        $this->mustSee($document);

        $result = $action->handle($document, $this->user());
        $this->success("{$result['imported']} TID masuk ke data merchant".($result['failed'] > 0 ? ", {$result['failed']} gagal (lihat alasannya di tiap baris)." : '.'));

        return back();
    }

    public function recheck(MerchantDocument $document, ImportMerchantDocument $action): RedirectResponse
    {
        $this->allow(Permissions::MASTER_TERMINAL_MANAGE);
        $this->mustSee($document);

        $fixed = $action->recheck($document);
        $this->success($fixed > 0 ? "{$fixed} baris sekarang sudah benar." : 'Belum ada baris yang berubah. Periksa lokasi atau TID-nya.');

        return back();
    }

    public function destroy(MerchantDocument $document): RedirectResponse
    {
        $this->allow(Permissions::MASTER_TERMINAL_MANAGE);
        $this->mustSee($document);

        // Once TIDs came from it, the document is the record of where they came from.
        if ($document->lines()->where('status', MerchantDocumentLine::IMPORTED)->exists()) {
            throw ValidationException::withMessages(['document' => 'Dokumen yang sudah mengimpor TID tidak dapat dihapus.']);
        }

        $document->delete();
        $this->success('Dokumen draf dihapus.');

        return to_route('master.documents.index');
    }

    public function template(): StreamedResponse
    {
        $this->allow(Permissions::MASTER_TERMINAL_MANAGE);

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w') ?: throw new \RuntimeException('Cannot open the output stream.');
            fputcsv($out, ['Nama Merchant', 'TID', 'MID', 'Alamat', 'Provinsi', 'Service Point', 'Area', 'Keterangan TID']);
            fputcsv($out, ['Toko Madura Bekasi', '145678', '', 'Jl. Contoh No. 1, Bekasi', 'Jawa Barat', 'SP Bekasi', 'Bekasi Kota', '']);
            fclose($out);
        }, 'template-dokumen-merchant.csv', ['Content-Type' => 'text/csv']);
    }

    private function mustSee(MerchantDocument $document): void
    {
        abort_unless(MerchantDocument::query()->visibleTo($this->user())->whereKey($document->id)->exists(), 404);
    }
}
