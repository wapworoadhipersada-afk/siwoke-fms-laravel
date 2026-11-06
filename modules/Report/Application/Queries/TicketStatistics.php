<?php

declare(strict_types=1);

namespace Modules\Report\Application\Queries;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Master\Application\Services\VendorAccess;
use Modules\Master\Domain\Models\Vendor;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;

/**
 * The numbers on the dashboard. Every figure starts from the tickets this user may see, so a regional
 * coordinator's dashboard and a manager's dashboard are different without any special-casing here.
 */
final class TicketStatistics
{
    /**
     * @return array{
     *     by_status: array<string, int>,
     *     open: int,
     *     overdue: int,
     *     created_today: int,
     *     closed_today: int,
     *     sla_percent: float|null,
     *     avg_resolution_hours: float|null,
     *     trend: list<array{date: string, created: int, closed: int}>,
     *     by_vendor: array<int, array{vendor: string, open: int}>,
     *     workload: array<int, array{technician: string, active: int}>
     * }
     */
    public function handle(User $user, bool $withWorkload = false): array
    {
        $timezone = (string) config('reports.timezone');
        $startOfToday = CarbonImmutable::now($timezone)->startOfDay();

        $byStatus = $this->base($user)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn (mixed $total): int => (int) $total)->all();

        $openStatuses = $this->openStatuses();
        $open = (int) array_sum(array_intersect_key($byStatus, array_flip($openStatuses)));

        return [
            'by_status' => $byStatus,
            'open' => $open,
            'overdue' => $this->base($user)->whereIn('status', $this->overdueCandidates())->where('sla_due_at', '<', now())->count(),
            'created_today' => $this->base($user)->where('received_at', '>=', $startOfToday)->count(),
            'closed_today' => $this->base($user)->where('closed_at', '>=', $startOfToday)->count(),
            ...$this->performance($user),
            'trend' => $this->trend($user, $timezone),
            'by_vendor' => $this->byVendor($user),
            'workload' => $withWorkload ? $this->workload($user) : [],
        ];
    }

    /**
     * @return Builder<Ticket>
     */
    private function base(User $user): Builder
    {
        return Ticket::query()->visibleTo($user)->tap(fn ($focused) => app(VendorAccess::class)->focus($focused, $user, 'tickets.vendor_id'));
    }

    /**
     * @return list<string>
     */
    private function openStatuses(): array
    {
        return array_map(
            static fn (TicketStatus $status): string => $status->value,
            array_values(array_filter(TicketStatus::cases(), static fn (TicketStatus $status): bool => ! $status->isFinal())),
        );
    }

    /**
     * Statuses where the clock is running: pending tickets wait on someone else, so they are not late.
     *
     * @return list<string>
     */
    private function overdueCandidates(): array
    {
        return array_values(array_filter($this->openStatuses(), static fn (string $status): bool => $status !== TicketStatus::Pending->value));
    }

    /**
     * @return array{sla_percent: float|null, avg_resolution_hours: float|null}
     */
    private function performance(User $user): array
    {
        $since = now()->subDays((int) config('reports.performance_days'));

        $row = $this->base($user)
            ->whereNotNull('final_result')
            ->where('closed_at', '>=', $since)
            ->selectRaw('count(*) as total, count(*) filter (where closed_at <= sla_due_at) as on_time, avg(extract(epoch from (closed_at - received_at)) / 3600) as hours')
            ->first();

        $total = (int) ($row?->getAttribute('total') ?? 0);

        return [
            'sla_percent' => $total === 0 ? null : round(((int) $row?->getAttribute('on_time')) / $total * 100, 1),
            'avg_resolution_hours' => $total === 0 ? null : round((float) $row?->getAttribute('hours'), 1),
        ];
    }

    /**
     * @return list<array{date: string, created: int, closed: int}>
     */
    private function trend(User $user, string $timezone): array
    {
        $days = (int) config('reports.trend_days');
        $from = CarbonImmutable::now($timezone)->startOfDay()->subDays($days - 1);

        $created = $this->perDay($user, 'received_at', $from, $timezone);
        $closed = $this->perDay($user, 'closed_at', $from, $timezone);
        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $from->addDays($i)->toDateString();
            $series[] = ['date' => $date, 'created' => (int) ($created[$date] ?? 0), 'closed' => (int) ($closed[$date] ?? 0)];
        }

        return $series;
    }

    /**
     * Tickets per calendar day (in the reporting timezone) for one of the two timestamp columns. The column
     * is chosen from a fixed pair, never taken from input.
     *
     * @return Collection<string, int>
     */
    private function perDay(User $user, string $column, CarbonImmutable $from, string $timezone): Collection
    {
        $select = $column === 'received_at'
            ? '((received_at at time zone ?)::date)::text as day, count(*) as total'
            : '((closed_at at time zone ?)::date)::text as day, count(*) as total';

        return $this->base($user)
            ->where($column === 'received_at' ? 'received_at' : 'closed_at', '>=', $from)
            ->selectRaw($select, [$timezone])
            ->groupByRaw('1')
            ->pluck('total', 'day');
    }

    /**
     * @return array<int, array{vendor: string, open: int}>
     */
    private function byVendor(User $user): array
    {
        $rows = $this->base($user)
            ->whereIn('status', $this->openStatuses())
            ->selectRaw('vendor_id, count(*) as total')
            ->groupBy('vendor_id')
            ->orderByDesc('total')
            ->limit(8)
            ->pluck('total', 'vendor_id');

        $names = Vendor::query()->whereIn('id', $rows->keys())->pluck('name', 'id');

        return $rows->map(fn (mixed $total, mixed $vendorId): array => ['vendor' => (string) ($names[$vendorId] ?? '-'), 'open' => (int) $total])->values()->all();
    }

    /**
     * @return array<int, array{technician: string, active: int}>
     */
    private function workload(User $user): array
    {
        $rows = $this->base($user)
            ->whereNotNull('technician_id')
            ->selectRaw('technician_id, count(*) as total')
            ->groupBy('technician_id')
            ->orderByDesc('total')
            ->limit(8)
            ->pluck('total', 'technician_id');

        $names = User::query()->whereIn('id', $rows->keys())->pluck('name', 'id');

        return $rows->map(fn (mixed $total, mixed $technicianId): array => ['technician' => (string) ($names[$technicianId] ?? '-'), 'active' => (int) $total])->values()->all();
    }
}
