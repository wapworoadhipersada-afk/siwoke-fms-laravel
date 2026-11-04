<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Shared\Support\Geo;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Application\Services\TicketWorkflow;
use Modules\Ticket\Domain\Enums\TicketEvent;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;

/**
 * The technician reports arriving. The position is compared with the site's coordinates and a result
 * far from the site is flagged on the history for review; it never blocks the technician, because
 * GPS indoors is unreliable.
 */
final class ArriveAtTicket
{
    public function __construct(private readonly TicketWorkflow $workflow) {}

    public function handle(Ticket $ticket, User $actor, ?ActionContext $context = null): Ticket
    {
        $context ??= new ActionContext;

        return DB::transaction(function () use ($ticket, $actor, $context): Ticket {
            $current = $this->workflow->lock($ticket);

            if ($this->workflow->alreadyApplied($context)) {
                return $current;
            }

            $visit = $this->workflow->openVisit($current);
            $this->workflow->assertTechnician($visit, $actor, $context);
            $at = $this->workflow->occurredAt($current, $context);

            $meta = [];
            $distance = null;
            $site = $current->site;

            if (! $context->hasLocation()) {
                $meta['location'] = 'missing';
            } elseif ($site->latitude !== null && $site->longitude !== null) {
                $distance = Geo::distanceMeters((float) $context->latitude, (float) $context->longitude, (float) $site->latitude, (float) $site->longitude);
                $meta['distance_m'] = $distance;
                $meta['outside_radius'] = $distance > (int) config('tickets.arrival_radius_meters');
            } else {
                $meta['location'] = 'site_has_no_coordinates';
            }

            $this->workflow->transition($current, TicketStatus::Arrived, TicketEvent::Arrived, $actor, $context, $at, $visit, null, $meta);

            $visit->forceFill([
                'arrived_at' => $at,
                'arrival_latitude' => $context->latitude,
                'arrival_longitude' => $context->longitude,
                'arrival_distance_m' => $distance,
            ])->save();

            return $current;
        });
    }
}
