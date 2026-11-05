<?php

declare(strict_types=1);

namespace Modules\Ticket\Application\BeritaAcara;

use Illuminate\Support\Facades\DB;

/**
 * Hands out Berita Acara numbers such as BA/BRI/2026/11/000123. The counter is a PostgreSQL sequence, so two
 * reports made at the same instant can never share a number. It is never reset or reused: a gap (from a
 * rolled-back attempt) is harmless, a duplicate would not be.
 */
final class NextNumber
{
    public function handle(string $vendorCode): string
    {
        $next = (int) DB::scalar("SELECT nextval('berita_acara_number_seq')");
        $month = now()->setTimezone((string) config('tickets.berita_acara.timezone'));

        return sprintf('BA/%s/%s/%06d', strtoupper($vendorCode), $month->format('Y/m'), $next);
    }
}
