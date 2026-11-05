<?php

declare(strict_types=1);

namespace Modules\Integration\Http\Api\V1\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Integration\Application\Services\StatusMapper;
use Modules\Integration\Domain\Models\IntegrationClient;
use Modules\Master\Domain\Models\Terminal;
use Modules\Shared\Http\ProblemException;
use Modules\Ticket\Application\Actions\CancelTicket;
use Modules\Ticket\Application\Actions\CreateTicket;
use Modules\Ticket\Domain\Enums\TicketPriority;
use Modules\Ticket\Domain\Enums\TicketSource;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketType;

/**
 * What a vendor's system can do with us: open a ticket, ask where it stands, withdraw it. Everything is
 * scoped to the vendor of the calling client; another vendor's tickets simply do not exist here.
 */
final class VendorTicketController extends Controller
{
    public function __construct(private readonly StatusMapper $status) {}

    public function store(Request $request, CreateTicket $create): JsonResponse
    {
        $client = $this->client($request);

        $data = $request->validate([
            'vendor_ticket_no' => ['required', 'string', 'max:60'],
            'tid' => ['required', 'string', 'max:30'],
            'type' => ['required', 'string', 'max:30'],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'scheduled_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $terminal = Terminal::query()->where('vendor_id', $client->vendor_id)->where('tid', $data['tid'])->first();

        if (! $terminal instanceof Terminal) {
            throw ValidationException::withMessages(['tid' => 'TID tidak dikenal untuk vendor ini.']);
        }

        $type = TicketType::query()->where('code', strtoupper($data['type']))->first();

        if (! $type instanceof TicketType) {
            throw ValidationException::withMessages(['type' => 'Jenis tiket tidak dikenal.']);
        }

        // The vendor resending the same request (a timeout on their side, say) gets the original back.
        $existing = $this->find($client, $data['vendor_ticket_no']);

        if ($existing instanceof Ticket) {
            return $this->existing($request, $existing, $terminal);
        }

        try {
            $ticket = $create->handle(
                $terminal,
                $type,
                null,
                isset($data['priority']) ? TicketPriority::from($data['priority']) : TicketPriority::Normal,
                isset($data['scheduled_at']) ? CarbonImmutable::parse($data['scheduled_at']) : null,
                $data['description'] ?? null,
                $data['vendor_ticket_no'],
                TicketSource::Api,
                $data,
            );
        } catch (ValidationException $exception) {
            // Two identical requests racing: the loser finds the winner's ticket.
            $raced = $this->find($client, $data['vendor_ticket_no']);

            if ($raced instanceof Ticket && isset($exception->errors()['vendor_ticket_no'])) {
                return $this->existing($request, $raced, $terminal);
            }

            throw $exception;
        }

        $request->attributes->set('integration.ticket_id', $ticket->id);

        return response()->json(['data' => $this->payload($ticket)], 201);
    }

    public function show(Request $request, string $vendorTicketNo): JsonResponse
    {
        $ticket = $this->findOrFail($this->client($request), $vendorTicketNo);
        $request->attributes->set('integration.ticket_id', $ticket->id);

        return response()->json(['data' => $this->payload($ticket)]);
    }

    public function cancel(Request $request, string $vendorTicketNo, CancelTicket $cancel): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $ticket = $this->findOrFail($this->client($request), $vendorTicketNo);
        $request->attributes->set('integration.ticket_id', $ticket->id);

        if ($ticket->status->isFinal() || ! $ticket->status->canMoveTo(TicketStatus::Cancelled)) {
            throw ProblemException::conflict('cannot_cancel', "Tiket berstatus {$this->status->forVendor($ticket->vendor_id, $ticket->status)}; tidak dapat dibatalkan.");
        }

        $cancelled = $cancel->handle($ticket, null, "Dibatalkan oleh vendor: {$data['reason']}");

        return response()->json(['data' => $this->payload($cancelled)]);
    }

    private function client(Request $request): IntegrationClient
    {
        $client = $request->attributes->get('integration.client');
        assert($client instanceof IntegrationClient);

        return $client;
    }

    private function find(IntegrationClient $client, string $vendorTicketNo): ?Ticket
    {
        return Ticket::query()
            ->where('vendor_id', $client->vendor_id)
            ->whereRaw('lower(vendor_ticket_no) = ?', [mb_strtolower(trim($vendorTicketNo))])
            ->first();
    }

    private function findOrFail(IntegrationClient $client, string $vendorTicketNo): Ticket
    {
        return $this->find($client, $vendorTicketNo)
            ?? throw ProblemException::notFound('ticket_not_found', 'Tiket tidak ditemukan.');
    }

    private function existing(Request $request, Ticket $ticket, Terminal $terminal): JsonResponse
    {
        // The same number for a different machine is not a retry, it is a clash the vendor must resolve.
        if ($ticket->terminal_id !== $terminal->id) {
            throw ProblemException::conflict('vendor_ticket_conflict', 'Nomor tiket ini sudah dipakai untuk TID lain.');
        }

        $request->attributes->set('integration.ticket_id', $ticket->id);

        return response()->json(['data' => $this->payload($ticket)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Ticket $ticket): array
    {
        $ticket->loadMissing(['type', 'terminal']);

        return [
            'ticket_no' => $ticket->ticket_no,
            'vendor_ticket_no' => $ticket->vendor_ticket_no,
            'type' => $ticket->type->code,
            'tid' => $ticket->terminal->tid,
            'status' => $this->status->forVendor($ticket->vendor_id, $ticket->status),
            'internal_status' => $ticket->status->value,
            'result' => $ticket->final_result,
            'received_at' => $ticket->received_at->toIso8601String(),
            'sla_due_at' => $ticket->sla_due_at->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
        ];
    }
}
