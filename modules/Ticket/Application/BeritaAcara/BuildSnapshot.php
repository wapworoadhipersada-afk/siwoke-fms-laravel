<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\BeritaAcara;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Modules\Document\Domain\Enums\AttachmentKind;
use Modules\Document\Domain\Models\Attachment;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketVisit;

/**
 * Collects every fact a Berita Acara states into one plain array. It is stored with the report and is what
 * the PDF is drawn from, so the report can be re-checked, or re-drawn, without trusting today's master data.
 */
final class BuildSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public function handle(TicketVisit $visit, string $number, int $revision): array
    {
        $ticket = Ticket::query()
            ->with(['type', 'vendor', 'terminal', 'site.merchant', 'site.location.parent.parent'])
            ->findOrFail($visit->ticket_id);
        $visit->loadMissing(['technician', 'reasonCode']);
        $technician = $visit->technician;
        $outcome = $visit->outcome;

        return [
            'ba' => ['number' => $number, 'revision' => $revision, 'generated_at' => $this->time(now())],
            'ticket' => [
                'ticket_no' => $ticket->ticket_no,
                'vendor_ticket_no' => $ticket->vendor_ticket_no,
                'type' => $ticket->type->name,
                'priority' => $ticket->priority->label(),
                'description' => $ticket->description,
                'received_at' => $this->time($ticket->received_at),
                'sla_due_at' => $this->time($ticket->sla_due_at),
            ],
            'vendor' => ['code' => $ticket->vendor->code, 'name' => $ticket->vendor->name],
            'merchant' => [
                'code' => $ticket->site->merchant->merchant_code,
                'name' => $ticket->site->merchant->name,
                'owner' => $ticket->site->merchant->owner_name,
                'phone' => $ticket->site->merchant->phone,
            ],
            'site' => [
                'name' => $ticket->site->name,
                'address' => $ticket->site->address,
                'area' => $ticket->site->location->name,
                'service_point' => $ticket->site->location->parent?->name,
                'province' => $ticket->site->location->parent?->parent?->name,
                'pic_name' => $ticket->site->pic_name,
                'pic_phone' => $ticket->site->pic_phone,
            ],
            'tid' => $ticket->terminal->tid,
            'technician' => ['name' => $technician->name, 'employee_no' => $technician->employee_no],
            'visit' => [
                'visit_no' => $visit->visit_no,
                'scheduled_at' => $this->time($visit->scheduled_at),
                'accepted_at' => $this->time($visit->accepted_at),
                'departed_at' => $this->time($visit->departed_at),
                'arrived_at' => $this->time($visit->arrived_at),
                'handling_started_at' => $this->time($visit->handling_started_at),
                'closed_at' => $this->time($visit->closed_at),
                'arrival_distance_m' => $visit->arrival_distance_m,
            ],
            'result' => [
                'outcome' => $outcome?->value,
                'outcome_label' => $outcome?->label(),
                'reason' => $visit->reasonCode?->name,
                'notes' => $visit->notes,
                'data' => $this->resultData($visit->result_data),
            ],
            'evidence' => $this->evidence($visit),
        ];
    }

    /**
     * The checklist answers as label/value lines, in the order the technician gave them.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{label: string, value: string}>
     */
    private function resultData(array $data): array
    {
        $lines = [];

        foreach ($data as $key => $value) {
            $lines[] = [
                'label' => ucfirst(str_replace('_', ' ', (string) $key)),
                'value' => match (true) {
                    is_bool($value) => $value ? 'Ya' : 'Tidak',
                    is_scalar($value) => (string) $value,
                    default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                },
            ];
        }

        return $lines;
    }

    /**
     * The photos and signatures on this visit, by reference and hash. The images themselves stay in storage.
     *
     * @return array<int, array{ulid: string, kind: string, label: string, sha256: string, captured_at: string|null}>
     */
    private function evidence(TicketVisit $visit): array
    {
        return Attachment::query()
            ->where('attachable_type', $visit->getMorphClass())
            ->where('attachable_id', $visit->id)
            ->where('kind', '!=', AttachmentKind::BeritaAcara->value)
            ->orderBy(DB::raw("CASE WHEN kind LIKE 'signature%' THEN 1 ELSE 0 END"))
            ->orderBy('id')
            ->get()
            ->map(fn (Attachment $file): array => [
                'ulid' => $file->ulid,
                'kind' => $file->kind->value,
                'label' => $file->kind->label(),
                'sha256' => $file->sha256,
                'captured_at' => $this->time($file->captured_at),
            ])
            ->values()
            ->all();
    }

    private function time(?DateTimeInterface $time): ?string
    {
        return $time?->format(DATE_ATOM);
    }
}
