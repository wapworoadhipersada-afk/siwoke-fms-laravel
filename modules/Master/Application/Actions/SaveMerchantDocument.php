<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Master\Application\Services\MerchantLineChecker;
use Modules\Master\Application\Services\MerchantSheetReader;
use Modules\Master\Domain\Models\MerchantDocument;
use Modules\Master\Domain\Models\MerchantDocumentLine;
use Modules\Master\Domain\Models\Vendor;
use Modules\Shared\Support\Audit;

/**
 * Records a document from the principal as a draft. Every line is checked on the way in (TID usable and not
 * taken, area found), so the person sees all the problems at once and nothing reaches the master data yet.
 */
final class SaveMerchantDocument
{
    /**
     * @param  list<array<string, string|null>>  $rows
     */
    public function handle(Vendor $vendor, string $number, string $receivedOn, ?string $notes, array $rows, User $actor, string $source = 'manual', ?string $sourceName = null): MerchantDocument
    {
        $rows = array_values(array_filter($rows, static fn (array $row): bool => trim(implode('', array_map(static fn (?string $cell): string => (string) $cell, $row))) !== ''));

        if ($rows === []) {
            throw ValidationException::withMessages(['lines' => 'Dokumen belum punya baris. Isi minimal satu merchant dengan TID-nya.']);
        }

        if (count($rows) > MerchantSheetReader::MAX_ROWS) {
            throw ValidationException::withMessages(['lines' => 'Maksimal '.MerchantSheetReader::MAX_ROWS.' baris per dokumen.']);
        }

        $checker = new MerchantLineChecker($vendor->id);

        try {
            return DB::transaction(function () use ($vendor, $number, $receivedOn, $notes, $rows, $actor, $source, $sourceName, $checker): MerchantDocument {
                $document = MerchantDocument::query()->create([
                    'vendor_id' => $vendor->id,
                    'document_number' => trim($number),
                    'received_on' => $receivedOn,
                    'notes' => $notes,
                    'source' => $source,
                    'source_name' => $sourceName,
                    'status' => MerchantDocument::DRAFT,
                    'created_by' => $actor->id,
                ]);

                $problems = 0;

                foreach ($rows as $index => $row) {
                    $result = $checker->check($row);

                    if ($result['error'] === null) {
                        $checker->claim($result['tid']);
                    } else {
                        $problems++;
                    }

                    MerchantDocumentLine::query()->create([
                        'merchant_document_id' => $document->id,
                        'line_no' => $index + 1,
                        'merchant_name' => trim((string) $row['merchant_name']),
                        'tid' => $result['tid'],
                        'mid' => $this->clean($row['mid'] ?? null),
                        'address' => $this->clean($row['address'] ?? null),
                        'province' => $this->clean($row['province'] ?? null),
                        'service_point' => $this->clean($row['service_point'] ?? null),
                        'area' => $this->clean($row['area'] ?? null),
                        'tid_note' => $this->clean($row['tid_note'] ?? null),
                        'location_id' => $result['location_id'],
                        'status' => $result['error'] === null ? MerchantDocumentLine::PENDING : MerchantDocumentLine::ERROR,
                        'error' => $result['error'],
                    ]);
                }

                Audit::record('master', 'document_saved', "Dokumen {$document->document_number} dicatat ({$source}): ".count($rows).' baris, '.$problems.' bermasalah', $actor, $document);

                return $document;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['document_number' => 'Nomor dokumen ini sudah tercatat untuk vendor tersebut.']);
        }
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
