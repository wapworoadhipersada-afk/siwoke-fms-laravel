<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Master\Application\Services\MerchantLineChecker;
use Modules\Master\Domain\Models\Merchant;
use Modules\Master\Domain\Models\MerchantDocument;
use Modules\Master\Domain\Models\MerchantDocumentLine;
use Modules\Master\Domain\Models\MerchantSite;
use Modules\Master\Domain\Models\Terminal;
use Modules\Shared\Support\Audit;

/**
 * Turns the good lines of a draft document into merchants, sites and TIDs. Each line stands alone: one that
 * fails is marked with the reason and the rest still go in. A merchant already known by its MID (or, without a
 * MID, by its TID code) is reused, so a merchant with several TIDs stays one merchant.
 */
final class ImportMerchantDocument
{
    public function __construct(
        private readonly CreateMerchant $merchants,
        private readonly CreateMerchantSite $sites,
        private readonly CreateTerminal $terminals,
    ) {}

    /**
     * Looks again at lines that were marked wrong (a missing area may have been added since).
     */
    public function recheck(MerchantDocument $document): int
    {
        $checker = new MerchantLineChecker($document->vendor_id);
        $fixed = 0;

        foreach ($document->lines()->where('status', MerchantDocumentLine::ERROR)->get() as $line) {
            $result = $checker->check($line->only(['merchant_name', 'tid', 'area', 'service_point', 'province']));

            if ($result['error'] === null) {
                $line->update(['status' => MerchantDocumentLine::PENDING, 'error' => null, 'location_id' => $result['location_id']]);
                $checker->claim($result['tid']);
                $fixed++;
            } else {
                $line->update(['error' => $result['error']]);
            }
        }

        return $fixed;
    }

    /**
     * @return array{imported: int, failed: int}
     */
    public function handle(MerchantDocument $document, User $actor): array
    {
        $imported = 0;
        $failed = 0;

        foreach ($document->lines()->where('status', MerchantDocumentLine::PENDING)->get() as $line) {
            try {
                DB::transaction(fn () => $this->importLine($document, $line));
                $imported++;
            } catch (ValidationException $exception) {
                $line->update(['status' => MerchantDocumentLine::ERROR, 'error' => (string) collect($exception->errors())->flatten()->first()]);
                $failed++;
            }
        }

        $document->update(['status' => $document->lines()->where('status', MerchantDocumentLine::PENDING)->exists() ? MerchantDocument::DRAFT : MerchantDocument::IMPORTED, 'imported_by' => $actor->id, 'imported_at' => now()]);

        Audit::record('master', 'document_imported', "Dokumen {$document->document_number} diimpor: {$imported} masuk, {$failed} gagal", $actor, $document);

        return ['imported' => $imported, 'failed' => $failed];
    }

    private function importLine(MerchantDocument $document, MerchantDocumentLine $line): void
    {
        if ($line->location_id === null) {
            throw ValidationException::withMessages(['area' => 'Area kelolaan belum ditemukan.']);
        }

        $code = $line->mid !== null ? mb_strtoupper($line->mid) : 'TID-'.$line->tid;

        $merchant = Merchant::query()->where('vendor_id', $document->vendor_id)->whereRaw('lower(merchant_code) = ?', [mb_strtolower($code)])->first()
            ?? $this->merchants->handle(['vendor_id' => $document->vendor_id, 'merchant_code' => $code, 'name' => $line->merchant_name]);

        $address = $line->address ?? '-';

        $site = MerchantSite::query()->where('merchant_id', $merchant->id)->where('location_id', $line->location_id)->where('address', $address)->first()
            ?? $this->sites->handle(['merchant_id' => $merchant->id, 'location_id' => $line->location_id, 'address' => $address]);

        $terminal = $this->terminals->handle(['merchant_site_id' => $site->id, 'tid' => $line->tid]);

        Terminal::query()->whereKey($terminal->id)->update(['note' => $line->tid_note, 'merchant_document_id' => $document->id]);

        $line->update(['status' => MerchantDocumentLine::IMPORTED, 'error' => null, 'terminal_id' => $terminal->id]);
    }
}
