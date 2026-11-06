<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Master\Application\Services\LocationAccess;
use Modules\Master\Application\Services\VendorAccess;
use Modules\Ticket\Domain\Models\Ticket;

/**
 * Whether an engineer is meant to work this ticket: the ticket's vendor is one of theirs, and its site lies in
 * one of their areas. Like the rest of the coverage rules it is opt-in: an engineer with no vendors assigned is
 * not limited by vendor, and one with no areas assigned is not limited by area (the assignment screen warns).
 */
final class TechnicianCoverage
{
    public function __construct(
        private readonly VendorAccess $vendors,
        private readonly LocationAccess $locations,
    ) {}

    public function covers(User $technician, Ticket $ticket): bool
    {
        return $this->vendors->mayUse($technician, $ticket->vendor_id) && $this->coversArea($technician, $ticket);
    }

    /**
     * @return string|null why not, in words for the person assigning, or null when covered
     */
    public function reasonNot(User $technician, Ticket $ticket): ?string
    {
        if (! $this->vendors->mayUse($technician, $ticket->vendor_id)) {
            return 'Teknisi ini tidak mencakup vendor tiket.';
        }

        return $this->coversArea($technician, $ticket) ? null : 'Lokasi tiket berada di luar area kerja teknisi ini.';
    }

    private function coversArea(User $technician, Ticket $ticket): bool
    {
        $assigned = DB::table('user_location_scopes')->where('user_id', $technician->id)->exists();

        if (! $assigned || $this->locations->isUnrestricted($technician)) {
            return true;
        }

        $ticket->loadMissing('site.location');

        return $this->locations->canAccess($technician, $ticket->site->location);
    }
}
