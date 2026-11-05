<?php

declare(strict_types=1);

namespace Modules\Ticket\Http\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\IAM\Domain\Permissions;
use Modules\Shared\Http\ModuleController;
use Modules\Ticket\Application\BeritaAcara\GenerateBeritaAcara;
use Modules\Ticket\Domain\Models\BeritaAcara;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketVisit;
use Symfony\Component\HttpFoundation\Response;

/**
 * Office access to Berita Acara: download, and (re)generate when the automatic one is missing or wrong.
 */
final class BeritaAcaraController extends ModuleController
{
    /**
     * Streams the PDF as a download. Anyone who can see the ticket and holds the download permission may fetch it;
     * out-of-scope tickets answer 404 like everywhere else.
     */
    public function download(Ticket $ticket, BeritaAcara $beritaAcara): Response
    {
        $this->allow(Permissions::DOCUMENT_BA_DOWNLOAD);
        abort_unless(Ticket::query()->visibleTo($this->user())->whereKey($ticket->id)->exists(), 404);
        abort_unless($beritaAcara->ticket_id === $ticket->id, 404);

        $attachment = $beritaAcara->attachment;
        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, str_replace('/', '-', $beritaAcara->label()).'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Makes the report for a closed visit that has none yet, or a new revision when a reason is given.
     */
    public function store(Request $request, Ticket $ticket, int $visitNo, GenerateBeritaAcara $generate): RedirectResponse
    {
        $this->allow(Permissions::DOCUMENT_BA_GENERATE);
        abort_unless(Ticket::query()->visibleTo($this->user())->whereKey($ticket->id)->exists(), 404);

        $visit = TicketVisit::query()->where('ticket_id', $ticket->id)->where('visit_no', $visitNo)->firstOrFail();
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $exists = BeritaAcara::query()->where('visit_id', $visit->id)->exists();

        $report = $this->translatingErrors([], fn (): BeritaAcara => $generate->handle($visit, $this->user(), $data['reason'] ?? null, regenerate: $exists));

        $this->success("Berita Acara {$report->label()} siap diunduh.");

        return to_route('tickets.show', $ticket);
    }
}
