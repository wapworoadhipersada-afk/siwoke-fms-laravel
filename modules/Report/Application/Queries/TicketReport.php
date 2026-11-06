<?php

declare(strict_types=1);

namespace Modules\Report\Application\Queries;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Master\Application\Services\VendorAccess;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketVisit;
use Throwable;

/**
 * The ticket report: one filtered query used by the screen and by the CSV export, so what is exported is
 * exactly what is on screen. It always starts from the tickets the user may see.
 */
final class TicketReport
{
    public const COLUMNS = [
        'No. tiket', 'No. tiket vendor', 'Vendor', 'Jenis', 'TID', 'Merchant', 'Titik layanan', 'Status', 'Prioritas',
        'Diterima', 'Batas SLA', 'Selesai', 'Teknisi terakhir', 'SLA tercapai',
    ];

    /**
     * @param  array{from?: string|null, to?: string|null, vendor?: string|null, status?: string|null, type?: string|null, q?: string|null}  $filters
     * @return Builder<Ticket>
     */
    public function query(User $user, array $filters): Builder
    {
        $timezone = (string) config('reports.timezone');
        $from = $this->date($filters['from'] ?? null, $timezone);
        $to = $this->date($filters['to'] ?? null, $timezone);
        $status = TicketStatus::tryFrom((string) ($filters['status'] ?? ''));
        $search = trim((string) ($filters['q'] ?? ''));
        $vendor = (string) ($filters['vendor'] ?? '');
        $type = (string) ($filters['type'] ?? '');
        $like = '%'.addcslashes($search, '%_\\').'%';

        return Ticket::query()
            ->visibleTo($user)
            ->tap(fn ($focused) => app(VendorAccess::class)->focus($focused, $user, 'tickets.vendor_id'))
            ->with(['vendor', 'type', 'terminal', 'site.merchant', 'site.location', 'visits.technician'])
            ->when($from !== null, fn (Builder $query) => $query->where('received_at', '>=', $from?->startOfDay()))
            ->when($to !== null, fn (Builder $query) => $query->where('received_at', '<=', $to?->endOfDay()))
            ->when($vendor !== '', fn (Builder $query) => $query->whereHas('vendor', fn (Builder $inner) => $inner->where('ulid', $vendor)))
            ->when($type !== '', fn (Builder $query) => $query->whereHas('type', fn (Builder $inner) => $inner->where('code', $type)))
            ->when($status instanceof TicketStatus, fn (Builder $query) => $query->where('status', $status?->value))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('ticket_no', 'ilike', $like)
                ->orWhere('vendor_ticket_no', 'ilike', $like)
                ->orWhereHas('terminal', fn (Builder $terminal) => $terminal->where('tid', 'ilike', $like))
                ->orWhereHas('site.merchant', fn (Builder $merchant) => $merchant->where('name', 'ilike', $like))))
            ->orderByDesc('received_at')
            ->orderByDesc('id');
    }

    /**
     * @return list<string>
     */
    public function row(Ticket $ticket): array
    {
        $timezone = (string) config('reports.timezone');
        $format = static fn (?\DateTimeInterface $time): string => $time === null ? '' : CarbonImmutable::instance($time)->setTimezone($timezone)->format('Y-m-d H:i');

        $last = $ticket->visits->sortByDesc('visit_no')->first();
        $met = $ticket->closed_at === null ? '' : ($ticket->closed_at->lte($ticket->sla_due_at) ? 'Ya' : 'Tidak');

        return [
            $ticket->ticket_no,
            (string) $ticket->vendor_ticket_no,
            $ticket->vendor->code,
            $ticket->type->name,
            $ticket->terminal->tid,
            $ticket->site->merchant->name,
            $ticket->site->location->name,
            $ticket->status->label(),
            $ticket->priority->label(),
            $format($ticket->received_at),
            $format($ticket->sla_due_at),
            $format($ticket->closed_at),
            $last instanceof TicketVisit ? $last->technician->name : '',
            $met,
        ];
    }

    private function date(?string $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $timezone);
        } catch (Throwable) {
            return null;
        }
    }
}
