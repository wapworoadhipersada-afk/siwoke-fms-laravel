<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Document\Application\Actions\DeleteAttachment;
use Modules\Document\Application\Actions\StoreAttachment;
use Modules\Document\Domain\Enums\AttachmentKind;
use Modules\Document\Domain\Models\Attachment;
use Modules\Document\Tests\Support\ImageFixtures as Img;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Tests\Support\MasterFixtures;
use Modules\Ticket\Application\Actions\CloseVisit;
use Modules\Ticket\Application\BeritaAcara\GenerateBeritaAcara;
use Modules\Ticket\Database\Seeders\TicketCatalogSeeder;
use Modules\Ticket\Database\Seeders\TicketEvidenceRulesSeeder;
use Modules\Ticket\Domain\Enums\ReasonType;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\BeritaAcara;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketVisit;
use Modules\Ticket\Tests\Support\TicketFixtures as Fx;
use Smalot\PdfParser\Parser;

beforeEach(function () {
    Storage::fake('attachments');
    $this->seed([RolesAndPermissionsSeeder::class, TicketCatalogSeeder::class, TicketEvidenceRulesSeeder::class]);
    $this->tech = Fx::technician();
});

/**
 * A ticket whose single visit was finished with photos and both signatures.
 */
function finishedTicket(object $test, ?User $technician = null): Ticket
{
    $technician ??= $test->tech;
    $ticket = Fx::handling(technician: $technician);
    $visit = $ticket->visits()->whereNull('outcome')->firstOrFail();

    // Each file differs in size, because the same file twice on one visit is refused as a repeat.
    foreach ([AttachmentKind::PhotoBefore, AttachmentKind::PhotoAfter, AttachmentKind::SignatureMerchant, AttachmentKind::SignatureTechnician] as $index => $kind) {
        app(StoreAttachment::class)->handle($visit, $technician, Img::upload($kind->isSignature() ? Img::transparentPng(600 + $index, 300) : Img::jpeg(800 + $index, 600)), $kind);
    }

    app(CloseVisit::class)->handle($ticket, $technician, VisitOutcome::Finish, notes: 'EDC diganti, tes transaksi berhasil.');

    return $ticket->fresh();
}

function visitOf(Ticket $ticket): TicketVisit
{
    return $ticket->visits()->firstOrFail();
}

function stored(BeritaAcara $report): string
{
    $attachment = $report->attachment;

    return (string) Storage::disk($attachment->disk)->get($attachment->path);
}

describe('generating', function () {
    it('produces a numbered PDF kept on the private disk, with a hash that matches the file', function () {
        $ticket = finishedTicket($this);

        $report = app(GenerateBeritaAcara::class)->handle(visitOf($ticket));
        $bytes = stored($report);

        expect($report->ba_number)->toMatch('#^BA/[A-Z0-9-]+/\d{4}/\d{2}/\d{6}$#')
            ->and($report->revision)->toBe(1)
            ->and($report->is_current)->toBeTrue()
            ->and($report->outcome)->toBe(VisitOutcome::Finish)
            ->and(str_starts_with($bytes, '%PDF'))->toBeTrue()
            ->and($report->attachment->kind)->toBe(AttachmentKind::BeritaAcara)
            ->and($report->attachment->uploaded_by)->toBeNull()
            ->and($report->attachment->sha256)->toBe(hash('sha256', $bytes))
            ->and($report->attachment->attachable_id)->toBe(visitOf($ticket)->id);

        $pages = (new Parser)->parseContent($bytes)->getPages();
        expect(count($pages))->toBeGreaterThanOrEqual(1);

        // The words on the page, as a reader would see them.
        $text = (new Parser)->parseContent($bytes)->getText();
        expect($text)->toContain($report->ba_number)->toContain($ticket->terminal->tid)->toContain($ticket->site->merchant->name)->toContain($this->tech->name);
    });

    it('states the facts of the visit in the frozen snapshot, and the hash can be recomputed from what was stored', function () {
        $ticket = finishedTicket($this);
        $report = app(GenerateBeritaAcara::class)->handle(visitOf($ticket));
        $snapshot = $report->fresh()->snapshot;

        expect($snapshot['ticket']['ticket_no'])->toBe($ticket->ticket_no)
            ->and($snapshot['tid'])->toBe($ticket->terminal->tid)
            ->and($snapshot['technician']['name'])->toBe($this->tech->name)
            ->and($snapshot['result']['outcome'])->toBe('finish')
            ->and($snapshot['result']['notes'])->toBe('EDC diganti, tes transaksi berhasil.')
            ->and(collect($snapshot['evidence'])->pluck('kind')->all())->toEqualCanonicalizing(['photo_before', 'photo_after', 'signature_merchant', 'signature_technician'])
            ->and(GenerateBeritaAcara::hash($snapshot))->toBe($report->snapshot_sha256);
    });

    it('is safe to call twice: the second call returns the report that exists', function () {
        $ticket = finishedTicket($this);

        $first = app(GenerateBeritaAcara::class)->handle(visitOf($ticket));
        $second = app(GenerateBeritaAcara::class)->handle(visitOf($ticket));

        expect($second->id)->toBe($first->id)
            ->and(BeritaAcara::query()->count())->toBe(1)
            ->and(Attachment::query()->where('kind', AttachmentKind::BeritaAcara->value)->count())->toBe(1);
    });

    it('gives every report its own number', function () {
        $one = app(GenerateBeritaAcara::class)->handle(visitOf(finishedTicket($this)));
        $two = app(GenerateBeritaAcara::class)->handle(visitOf(finishedTicket($this)));

        expect($one->ba_number)->not->toBe($two->ba_number);
    });

    it('refuses a visit that is still open', function () {
        $ticket = Fx::handling(technician: $this->tech);

        expect(fn () => app(GenerateBeritaAcara::class)->handle(visitOf($ticket)))->toThrow(ValidationException::class);
        expect(BeritaAcara::query()->count())->toBe(0);
    });

    it('also reports a pending visit and a done-negative visit', function (VisitOutcome $outcome, ReasonType $type, string $code) {
        $ticket = Fx::handling(technician: $this->tech);
        $visit = $ticket->visits()->firstOrFail();
        app(StoreAttachment::class)->handle($visit, $this->tech, Img::upload(Img::jpeg()), AttachmentKind::PhotoEvidence);

        app(CloseVisit::class)->handle($ticket, $this->tech, $outcome, reason: Fx::reason($type, $code), notes: 'Toko tutup.');

        $report = app(GenerateBeritaAcara::class)->handle(visitOf($ticket));

        expect($report->outcome)->toBe($outcome)
            ->and($report->snapshot['result']['reason'])->not->toBeNull();
    })->with([
        'pending' => [VisitOutcome::Pending, ReasonType::Pending, 'MERCHANT_NOT_READY'],
        'done negative' => [VisitOutcome::DoneNegative, ReasonType::Negative, 'MERCHANT_REFUSED'],
    ]);

    it('still produces a report when a photo file has gone missing, leaving that photo out', function () {
        $ticket = finishedTicket($this);
        $photo = Attachment::query()->where('kind', AttachmentKind::PhotoBefore->value)->firstOrFail();
        Storage::disk($photo->disk)->delete($photo->path);

        $report = app(GenerateBeritaAcara::class)->handle(visitOf($ticket));

        expect(str_starts_with(stored($report), '%PDF'))->toBeTrue();
    });
});

describe('revisions', function () {
    it('keeps a report exactly as it was made even after the merchant is renamed', function () {
        $ticket = finishedTicket($this);
        $original = app(GenerateBeritaAcara::class)->handle(visitOf($ticket));
        $before = $original->snapshot['merchant']['name'];

        $ticket->site->merchant->forceFill(['name' => 'Nama Baru Merchant'])->save();

        expect($original->fresh()->snapshot['merchant']['name'])->toBe($before)
            ->and(GenerateBeritaAcara::hash($original->fresh()->snapshot))->toBe($original->snapshot_sha256);
    });

    it('makes a correction as a new revision with the same number, and retires the old one', function () {
        $ticket = finishedTicket($this);
        $actor = Fx::dispatcher();
        $first = app(GenerateBeritaAcara::class)->handle(visitOf($ticket));

        $ticket->site->merchant->forceFill(['name' => 'Nama Benar Merchant'])->save();
        $second = app(GenerateBeritaAcara::class)->handle(visitOf($ticket), $actor, 'Nama merchant salah ketik', regenerate: true);

        expect($second->ba_number)->toBe($first->ba_number)
            ->and($second->revision)->toBe(2)
            ->and($second->is_current)->toBeTrue()
            ->and($second->snapshot['merchant']['name'])->toBe('Nama Benar Merchant')
            ->and($second->regenerate_reason)->toBe('Nama merchant salah ketik')
            ->and($second->generated_by)->toBe($actor->id)
            ->and($second->label())->toEndWith('rev 2')
            ->and($first->fresh()->is_current)->toBeFalse()
            ->and($first->fresh()->snapshot['merchant']['name'])->not->toBe('Nama Benar Merchant')
            ->and($first->attachment_id)->not->toBe($second->attachment_id);
    });

    it('asks for a reason before regenerating', function () {
        $ticket = finishedTicket($this);
        app(GenerateBeritaAcara::class)->handle(visitOf($ticket));

        expect(fn () => app(GenerateBeritaAcara::class)->handle(visitOf($ticket), regenerate: true, reason: '  '))->toThrow(ValidationException::class);
        expect(BeritaAcara::query()->count())->toBe(1);
    });
});

describe('tamper resistance', function () {
    it('refuses to change the content of a report in the database', function () {
        $report = app(GenerateBeritaAcara::class)->handle(visitOf(finishedTicket($this)));

        expect(fn () => DB::table('berita_acaras')->where('id', $report->id)->update(['snapshot' => json_encode(['x' => 1])]))->toThrow(QueryException::class);
    });

    it('refuses to delete a report', function () {
        $report = app(GenerateBeritaAcara::class)->handle(visitOf(finishedTicket($this)));

        expect(fn () => DB::table('berita_acaras')->where('id', $report->id)->delete())->toThrow(QueryException::class);
    });

    it('cannot be deleted or replaced by the technician through the mobile attachment endpoints', function () {
        $ticket = finishedTicket($this);
        $report = app(GenerateBeritaAcara::class)->handle(visitOf($ticket));

        expect(fn () => app(DeleteAttachment::class)->handle($report->attachment, $this->tech))
            ->toThrow(AuthorizationException::class);
    });

    it('cannot be uploaded by a person', function () {
        $ticket = Fx::handling(technician: $this->tech);

        expect(fn () => app(StoreAttachment::class)->handle(visitOf($ticket), $this->tech, Img::upload(Img::jpeg()), AttachmentKind::BeritaAcara))
            ->toThrow(ValidationException::class);
    });
});

describe('automatic generation', function () {
    it('is produced in the background when the technician closes the visit', function () {
        config(['tickets.berita_acara.auto_generate' => true]);

        $ticket = finishedTicket($this);

        $report = BeritaAcara::query()->where('ticket_id', $ticket->id)->sole();

        expect($report->is_current)->toBeTrue()
            ->and($report->generated_by)->toBeNull();
    });

    it('is also produced when a visit ends pending', function () {
        config(['tickets.berita_acara.auto_generate' => true]);
        $ticket = Fx::handling(technician: $this->tech);
        app(StoreAttachment::class)->handle(visitOf($ticket), $this->tech, Img::upload(Img::jpeg()), AttachmentKind::PhotoEvidence);

        app(CloseVisit::class)->handle($ticket, $this->tech, VisitOutcome::Pending, reason: Fx::reason(ReasonType::Pending, 'MERCHANT_NOT_READY'));

        expect(BeritaAcara::query()->where('ticket_id', $ticket->id)->sole()->outcome)->toBe(VisitOutcome::Pending);
    });

    it('does not make a report for a ticket that is merely moving along', function () {
        config(['tickets.berita_acara.auto_generate' => true]);

        Fx::handling(technician: $this->tech);

        expect(BeritaAcara::query()->count())->toBe(0);
    });

    it('can be switched off', function () {
        config(['tickets.berita_acara.auto_generate' => false]);

        finishedTicket($this);

        expect(BeritaAcara::query()->count())->toBe(0);
    });
});

describe('downloading', function () {
    it('lets an office user with the permission download the PDF', function () {
        $ticket = finishedTicket($this);
        $report = app(GenerateBeritaAcara::class)->handle(visitOf($ticket));

        $response = $this->actingAs(Fx::dispatcher())->get(route('tickets.berita-acara.download', [$ticket, $report]));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($response->headers->get('Content-Disposition'))->toContain('attachment')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });

    it('refuses someone without the download permission', function () {
        $ticket = finishedTicket($this);
        $report = app(GenerateBeritaAcara::class)->handle(visitOf($ticket));

        $this->actingAs($this->tech)->get(route('tickets.berita-acara.download', [$ticket, $report]))->assertForbidden();
    });

    it('answers 404 to an office user whose area does not include the ticket', function () {
        $ticket = finishedTicket($this);
        $report = app(GenerateBeritaAcara::class)->handle(visitOf($ticket));
        $elsewhere = Fx::scopedDispatcher([MasterFixtures::chain()['area']]);

        $this->actingAs($elsewhere)->get(route('tickets.berita-acara.download', [$ticket, $report]))->assertNotFound();
    });

    it('does not serve a report under another ticket\'s address', function () {
        $ticket = finishedTicket($this);
        $other = finishedTicket($this);
        $report = app(GenerateBeritaAcara::class)->handle(visitOf($ticket));

        $this->actingAs(Fx::dispatcher())->get(route('tickets.berita-acara.download', [$other, $report]))->assertNotFound();
    });

    it('does not offer the PDF through the evidence gallery of the mobile app', function () {
        $ticket = finishedTicket($this);
        app(GenerateBeritaAcara::class)->handle(visitOf($ticket));

        Sanctum::actingAs($this->tech, ['*']);
        $kinds = collect($this->getJson("/api/mobile/v1/tickets/{$ticket->ulid}/attachments")->json('data'))->pluck('kind');

        expect($kinds)->not->toContain('ba_pdf');
    });
});

describe('regenerating from the web', function () {
    it('creates a revision for a user allowed to', function () {
        $ticket = finishedTicket($this);
        $first = app(GenerateBeritaAcara::class)->handle(visitOf($ticket));

        $this->actingAs(Fx::dispatcher())
            ->post(route('tickets.berita-acara.store', [$ticket, 1]), ['reason' => 'Perbaikan data'])
            ->assertRedirect(route('tickets.show', $ticket));

        expect(BeritaAcara::query()->where('visit_id', visitOf($ticket)->id)->count())->toBe(2)
            ->and($first->fresh()->is_current)->toBeFalse();
    });

    it('makes the first report when automatic generation missed it', function () {
        $ticket = finishedTicket($this);

        $this->actingAs(Fx::dispatcher())->post(route('tickets.berita-acara.store', [$ticket, 1]))->assertRedirect();

        expect(BeritaAcara::query()->where('ticket_id', $ticket->id)->sole()->revision)->toBe(1);
    });

    it('requires a reason for a correction', function () {
        $ticket = finishedTicket($this);
        app(GenerateBeritaAcara::class)->handle(visitOf($ticket));

        $this->actingAs(Fx::dispatcher())->post(route('tickets.berita-acara.store', [$ticket, 1]))->assertSessionHasErrors('reason');
    });

    it('is not allowed for a coordinator, who may only download', function () {
        $ticket = finishedTicket($this);
        $coordinator = User::factory()->create()->assignRole(RoleName::Coordinator->value)->givePermissionTo(Permissions::DATA_SCOPE_ALL);

        $this->actingAs($coordinator)->post(route('tickets.berita-acara.store', [$ticket, 1]))->assertForbidden();
    });
});
