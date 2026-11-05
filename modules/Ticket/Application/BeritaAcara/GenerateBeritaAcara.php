<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\BeritaAcara;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Document\Application\Actions\StoreGeneratedDocument;
use Modules\Document\Domain\Enums\AttachmentKind;
use Modules\Shared\Support\Audit;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\BeritaAcara;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketVisit;
use Throwable;

/**
 * Makes the Berita Acara of a finished visit. Safe to call twice: the second call returns the report that
 * already exists. A correction is only made on purpose, with a reason, and becomes a new revision that
 * keeps the same number; the old revision stays on record, marked as no longer current.
 */
final class GenerateBeritaAcara
{
    public function __construct(
        private readonly BuildSnapshot $snapshots,
        private readonly NextNumber $numbers,
        private readonly RenderPdf $renderer,
        private readonly StoreGeneratedDocument $storage,
    ) {}

    public function handle(TicketVisit $visit, ?User $actor = null, ?string $reason = null, bool $regenerate = false): BeritaAcara
    {
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        if ($regenerate && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'Alasan pembuatan ulang wajib diisi.']);
        }

        return DB::transaction(function () use ($visit, $actor, $reason, $regenerate): BeritaAcara {
            // Two workers racing on the same visit queue up here; the second finds the first one's report.
            $locked = TicketVisit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();

            $this->assertReportable($locked);

            $current = BeritaAcara::query()->where('visit_id', $locked->id)->where('is_current', true)->first();

            if ($current instanceof BeritaAcara && ! $regenerate) {
                return $current;
            }

            $ticket = Ticket::query()->with('vendor')->findOrFail($locked->ticket_id);
            $number = $current instanceof BeritaAcara ? $current->ba_number : $this->numbers->handle($ticket->vendor->code);
            $revision = (int) BeritaAcara::query()->where('visit_id', $locked->id)->max('revision') + 1;

            $snapshot = $this->snapshots->handle($locked, $number, $revision);
            $bytes = $this->renderer->handle($snapshot);

            $attachment = $this->storage->handle($locked, AttachmentKind::BeritaAcara, $bytes, $this->fileName($number, $revision));

            try {
                if ($current instanceof BeritaAcara) {
                    // The unique "one current report per visit" index needs the old one retired first.
                    $current->forceFill(['is_current' => false])->save();
                }

                $report = new BeritaAcara([
                    'ba_number' => $number,
                    'revision' => $revision,
                    'ticket_id' => $locked->ticket_id,
                    'visit_id' => $locked->id,
                    'attachment_id' => $attachment->id,
                    'outcome' => $locked->outcome,
                    'snapshot' => $snapshot,
                    'snapshot_sha256' => self::hash($snapshot),
                    'is_current' => true,
                    'regenerate_reason' => $revision > 1 ? $reason : null,
                    'generated_by' => $actor?->id,
                    'generated_at' => now(),
                ]);
                $report->save();

                Audit::record('document', $revision > 1 ? 'ba_regenerated' : 'ba_generated', "Berita Acara {$report->label()} dibuat", $actor, $report, [
                    'ticket_no' => $ticket->ticket_no,
                    'visit_no' => $locked->visit_no,
                    'reason' => $reason,
                ]);

                return $report;
            } catch (Throwable $exception) {
                // The transaction rolls the rows back, so the PDF would be an orphan nobody could ever find.
                Storage::disk($attachment->disk)->delete($attachment->path);

                throw $exception;
            }
        });
    }

    /**
     * A stable fingerprint of the snapshot. Keys are sorted first because PostgreSQL stores jsonb in its own
     * order, and the hash must come out the same when computed again from what was stored.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function hash(array $snapshot): string
    {
        return hash('sha256', (string) json_encode(self::sorted($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function assertReportable(TicketVisit $visit): void
    {
        $allowed = [VisitOutcome::Finish, VisitOutcome::Pending, VisitOutcome::DoneNegative];

        if (! $visit->outcome instanceof VisitOutcome || ! in_array($visit->outcome, $allowed, true)) {
            throw ValidationException::withMessages(['visit' => 'Berita Acara hanya dibuat untuk kunjungan yang sudah ditutup.']);
        }
    }

    private function fileName(string $number, int $revision): string
    {
        $base = str_replace('/', '-', $number);

        return $revision > 1 ? "{$base}-rev{$revision}.pdf" : "{$base}.pdf";
    }
}
