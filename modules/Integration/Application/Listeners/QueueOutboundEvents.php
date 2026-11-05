<?php

declare(strict_types=1);

namespace Modules\Integration\Application\Listeners;

use Modules\Integration\Application\Jobs\DeliverOutboundEvent;
use Modules\Integration\Application\Services\OutboundPayload;
use Modules\Integration\Domain\Models\IntegrationClient;
use Modules\Integration\Domain\Models\OutboundEvent;
use Modules\Ticket\Domain\Events\TicketStatusRecorded;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketStatusHistory;

/**
 * Writes the outbox rows for a status change, inside the same transaction as the change. Delivery happens
 * later, in the background; this only makes sure the promise to tell the vendor cannot be lost.
 */
final class QueueOutboundEvents
{
    public function __construct(private readonly OutboundPayload $payloads) {}

    public function handle(TicketStatusRecorded $event): void
    {
        $ticket = Ticket::query()->find($event->ticketId);

        // A vendor only hears about tickets it knows by its own number.
        if (! $ticket instanceof Ticket || $ticket->vendor_ticket_no === null) {
            return;
        }

        $clients = IntegrationClient::query()
            ->where('vendor_id', $ticket->vendor_id)
            ->where('is_active', true)
            ->whereNotNull('webhook_url')
            ->get();

        if ($clients->isEmpty()) {
            return;
        }

        $history = TicketStatusHistory::query()->findOrFail($event->historyId);
        $payload = $this->payloads->build($ticket, $history);

        foreach ($clients as $client) {
            // Checked rather than caught: a failed insert would poison the surrounding PostgreSQL transaction.
            $queued = OutboundEvent::query()->where('client_id', $client->id)->where('history_id', $history->id)->exists();

            if ($queued) {
                continue;
            }

            $outbound = OutboundEvent::query()->create([
                'client_id' => $client->id,
                'vendor_id' => $ticket->vendor_id,
                'ticket_id' => $ticket->id,
                'history_id' => $history->id,
                'event' => 'ticket.status_changed',
                'payload' => $payload,
                'next_attempt_at' => now(),
            ]);

            DeliverOutboundEvent::dispatch($outbound->id)->afterCommit();
        }
    }
}
