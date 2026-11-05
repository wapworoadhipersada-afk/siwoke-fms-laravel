<?php

declare(strict_types=1);

namespace Modules\Report\Http\Web\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Domain\Models\Vendor;
use Modules\Report\Application\Queries\TicketReport;
use Modules\Report\Application\Services\CsvCell;
use Modules\Shared\Http\ModuleController;
use Modules\Shared\Support\Audit;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketType;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TicketReportController extends ModuleController
{
    public function __construct(private readonly TicketReport $report) {}

    public function index(Request $request): Response
    {
        $this->allow(Permissions::REPORT_VIEW);
        $this->allow(Permissions::TICKET_VIEW);

        $filters = $this->filters($request);
        $user = $this->user();

        $tickets = $this->report->query($user, $filters)->paginate(25)->withQueryString()->through(fn (Ticket $ticket): array => [
            'id' => $ticket->ulid,
            'cells' => $this->report->row($ticket),
        ]);

        return Inertia::render('reports/Tickets', [
            'columns' => TicketReport::COLUMNS,
            'tickets' => $tickets,
            'filters' => $filters,
            'vendors' => Vendor::query()->orderBy('name')->get()->map(fn (Vendor $vendor): array => ['ulid' => $vendor->ulid, 'label' => "{$vendor->code} — {$vendor->name}"])->values()->all(),
            'types' => TicketType::query()->orderBy('name')->get()->map(fn (TicketType $type): array => ['code' => $type->code, 'label' => $type->name])->values()->all(),
            'statuses' => collect(TicketStatus::cases())->map(fn (TicketStatus $status): array => ['value' => $status->value, 'label' => $status->label()])->all(),
            'can' => ['export' => $user->can(Permissions::REPORT_EXPORT)],
        ]);
    }

    /**
     * Streams the same rows as the screen as a CSV file, up to a fixed ceiling, and notes who took it.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->allow(Permissions::REPORT_EXPORT);
        $this->allow(Permissions::TICKET_VIEW);

        $filters = $this->filters($request);
        $user = $this->user();
        $limit = (int) config('reports.max_export_rows');
        $query = $this->report->query($user, $filters);

        Audit::record('report', 'ticket_export', 'Laporan tiket diekspor', $user, null, ['filters' => array_filter($filters), 'limit' => $limit]);

        $name = 'laporan-tiket-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query, $limit): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            // The byte-order mark makes Excel read the file as UTF-8, so names with accents survive.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, TicketReport::COLUMNS);

            $written = 0;

            $query->chunk(500, function ($tickets) use ($out, &$written, $limit): bool {
                foreach ($tickets as $ticket) {
                    if ($written >= $limit) {
                        return false;
                    }

                    fputcsv($out, CsvCell::row($this->report->row($ticket)));
                    $written++;
                }

                return true;
            });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * @return array{from: string, to: string, vendor: string, status: string, type: string, q: string}
     */
    private function filters(Request $request): array
    {
        return [
            'from' => trim((string) $request->query('from')),
            'to' => trim((string) $request->query('to')),
            'vendor' => trim((string) $request->query('vendor')),
            'status' => trim((string) $request->query('status')),
            'type' => trim((string) $request->query('type')),
            'q' => trim((string) $request->query('q')),
        ];
    }
}
