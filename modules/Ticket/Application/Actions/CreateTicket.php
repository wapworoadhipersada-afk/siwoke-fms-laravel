<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Master\Domain\Enums\TerminalStatus;
use Modules\Master\Domain\Models\Terminal;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Application\Services\TicketWorkflow;
use Modules\Ticket\Domain\Enums\TicketEvent;
use Modules\Ticket\Domain\Enums\TicketPriority;
use Modules\Ticket\Domain\Enums\TicketSource;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketType;

/**
 * Opens a ticket for a terminal. The vendor and site come from the terminal, so a ticket can never
 * disagree with the master data about who the job is for or where it is.
 */
final class CreateTicket
{
    public function __construct(private readonly TicketWorkflow $workflow) {}

    /**
     * @param  array<string, mixed>  $payload  the vendor's original request, kept verbatim
     */
    public function handle(
        Terminal $terminal,
        TicketType $type,
        ?User $creator,
        TicketPriority $priority = TicketPriority::Normal,
        ?CarbonInterface $scheduledAt = null,
        ?string $description = null,
        ?string $vendorTicketNo = null,
        TicketSource $source = TicketSource::Manual,
        array $payload = [],
    ): Ticket {
        if (! $type->is_active) {
            throw ValidationException::withMessages(['ticket_type' => 'Jenis tiket ini tidak aktif.']);
        }

        if ($terminal->status === TerminalStatus::Removed) {
            throw ValidationException::withMessages(['terminal' => 'TID ini sudah ditarik; tidak dapat dibuatkan tiket.']);
        }

        $terminal->loadMissing('vendor');

        if (! $terminal->vendor->is_active) {
            throw ValidationException::withMessages(['vendor' => 'Vendor tidak aktif.']);
        }

        $vendorTicketNo = $vendorTicketNo !== null && trim($vendorTicketNo) !== '' ? trim($vendorTicketNo) : null;
        $receivedAt = now();

        try {
            return DB::transaction(function () use ($terminal, $type, $creator, $priority, $scheduledAt, $description, $vendorTicketNo, $source, $payload, $receivedAt): Ticket {
                $ticket = new Ticket([
                    'vendor_ticket_no' => $vendorTicketNo,
                    'vendor_id' => $terminal->vendor_id,
                    'terminal_id' => $terminal->id,
                    'merchant_site_id' => $terminal->merchant_site_id,
                    'ticket_type_id' => $type->id,
                    'source' => $source,
                    'priority' => $priority,
                    'description' => $description,
                    'scheduled_at' => $scheduledAt,
                    'received_at' => $receivedAt,
                    'sla_due_at' => $receivedAt->copy()->addHours($type->sla_hours),
                    'payload' => $payload,
                    'created_by' => $creator?->id,
                ]);

                $ticket->forceFill(['ticket_no' => $this->nextNumber()])->save();

                $this->workflow->record($ticket, null, TicketStatus::Received, TicketEvent::Created, $creator, new ActionContext, $receivedAt, meta: ['source' => $source->value]);

                return $ticket;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['vendor_ticket_no' => 'Nomor tiket vendor ini sudah terdaftar.']);
        }
    }

    private function nextNumber(): string
    {
        $row = DB::selectOne("select nextval('ticket_number_seq') as number");

        return sprintf('TK-%s-%06d', now()->format('Ymd'), (int) ($row->number ?? 0));
    }
}
