<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Document\Application\Actions\DeleteAttachment;
use Modules\Document\Application\Actions\StoreAttachment;
use Modules\Document\Domain\Enums\AttachmentKind;
use Modules\Document\Domain\Models\Attachment;
use Modules\Document\Tests\Support\ImageFixtures as Img;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Ticket\Application\Actions\AcceptTicket;
use Modules\Ticket\Application\Actions\ArriveAtTicket;
use Modules\Ticket\Application\Actions\AssignTicket;
use Modules\Ticket\Application\Actions\CloseVisit;
use Modules\Ticket\Application\Actions\StartHandling;
use Modules\Ticket\Database\Seeders\TicketCatalogSeeder;
use Modules\Ticket\Database\Seeders\TicketEvidenceRulesSeeder;
use Modules\Ticket\Domain\Enums\ReasonType;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Tests\Support\TicketFixtures as Fx;

const EVIDENCE_TICKETS = '/api/mobile/v1/tickets';

beforeEach(function () {
    Storage::fake('attachments');
    $this->seed([RolesAndPermissionsSeeder::class, TicketCatalogSeeder::class, TicketEvidenceRulesSeeder::class]);
    $this->tech = Fx::technician();
    $this->ticket = Fx::handling(technician: $this->tech);
});

function attach(object $test, AttachmentKind $kind, ?string $bytes = null): Attachment
{
    $visit = $test->ticket->visits()->whereNull('outcome')->firstOrFail();

    return app(StoreAttachment::class)->handle($visit, $test->tech, Img::upload($bytes ?? ($kind->isSignature() ? Img::transparentPng() : Img::jpeg())), $kind);
}

function attachAllForFinish(object $test): void
{
    attach($test, AttachmentKind::PhotoBefore);
    attach($test, AttachmentKind::PhotoAfter);
    attach($test, AttachmentKind::SignatureMerchant);
}

describe('required evidence', function () {
    it('blocks finishing until every required item is attached, and says exactly what is missing', function () {
        attach($this, AttachmentKind::PhotoBefore);

        try {
            app(CloseVisit::class)->handle($this->ticket, $this->tech, VisitOutcome::Finish);
            $this->fail('The visit should not have closed.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['attachments'][0];

            expect($message)->toContain('Foto sesudah pekerjaan: butuh 1, baru ada 0')
                ->and($message)->toContain('Tanda tangan merchant: butuh 1, baru ada 0')
                ->and($message)->not->toContain('Foto sebelum');
        }

        expect($this->ticket->fresh()->status)->toBe(TicketStatus::Handling)
            ->and($this->ticket->visits()->sole()->outcome)->toBeNull();
    });

    it('lets it finish once everything is there', function () {
        attachAllForFinish($this);

        app(CloseVisit::class)->handle($this->ticket, $this->tech, VisitOutcome::Finish);

        expect($this->ticket->fresh()->status)->toBe(TicketStatus::Finished);
    });

    it('asks for a proof photo when a visit ends pending or done negative', function (VisitOutcome $outcome, ReasonType $type, string $code) {
        $reason = Fx::reason($type, $code);

        expect(fn () => app(CloseVisit::class)->handle($this->ticket, $this->tech, $outcome, reason: $reason, notes: 'Catatan'))
            ->toThrow(ValidationException::class, 'Foto bukti kunjungan');

        attach($this, AttachmentKind::PhotoEvidence);

        app(CloseVisit::class)->handle($this->ticket, $this->tech, $outcome, reason: $reason, notes: 'Catatan');

        expect($this->ticket->fresh()->status->value)->toBe($outcome->value);
    })->with([
        'pending' => [VisitOutcome::Pending, ReasonType::Pending, 'NETWORK_ISSUE'],
        'done negative' => [VisitOutcome::DoneNegative, ReasonType::Negative, 'ADDRESS_NOT_FOUND'],
    ]);

    it('does not count a file that was withdrawn', function () {
        attachAllForFinish($this);
        $photo = $this->ticket->visits()->sole()->attachments()->where('kind', 'photo_after')->sole();
        app(DeleteAttachment::class)->handle($photo, $this->tech);

        app(CloseVisit::class)->handle($this->ticket, $this->tech, VisitOutcome::Finish);
    })->throws(ValidationException::class, 'Foto sesudah pekerjaan');

    it('does not count evidence from an earlier visit', function () {
        attach($this, AttachmentKind::PhotoEvidence);
        app(CloseVisit::class)->handle($this->ticket, $this->tech, VisitOutcome::Pending, reason: Fx::reason(ReasonType::Pending, 'NETWORK_ISSUE'));

        $second = Fx::technician();
        app(AssignTicket::class)->handle($this->ticket->fresh(), $second, Fx::dispatcher());
        app(AcceptTicket::class)->handle($this->ticket->fresh(), $second);
        app(ArriveAtTicket::class)->handle($this->ticket->fresh(), $second);
        app(StartHandling::class)->handle($this->ticket->fresh(), $second);

        // The first visit's photo does not satisfy the second visit.
        app(CloseVisit::class)->handle($this->ticket->fresh(), $second, VisitOutcome::Finish);
    })->throws(ValidationException::class, 'Foto sebelum pekerjaan');

    it('asks nothing of a ticket type that has no rules', function () {
        $this->ticket->type->update(['evidence_rules' => []]);

        app(CloseVisit::class)->handle($this->ticket->fresh(), $this->tech, VisitOutcome::Finish);

        expect($this->ticket->fresh()->status)->toBe(TicketStatus::Finished);
    });

    it('lets each type ask for something different', function () {
        $this->ticket->type->update(['evidence_rules' => ['finish' => ['photo_after' => 2]]]);
        attach($this, AttachmentKind::PhotoAfter);

        expect(fn () => app(CloseVisit::class)->handle($this->ticket->fresh(), $this->tech, VisitOutcome::Finish))->toThrow(ValidationException::class, 'butuh 2, baru ada 1');

        attach($this, AttachmentKind::PhotoAfter);
        app(CloseVisit::class)->handle($this->ticket->fresh(), $this->tech, VisitOutcome::Finish);

        expect($this->ticket->fresh()->status)->toBe(TicketStatus::Finished);
    });

    it('ignores rules naming a kind that does not exist', function () {
        $this->ticket->type->update(['evidence_rules' => ['finish' => ['hologram' => 5]]]);

        app(CloseVisit::class)->handle($this->ticket->fresh(), $this->tech, VisitOutcome::Finish);

        expect($this->ticket->fresh()->status)->toBe(TicketStatus::Finished);
    });

    it('seeds sensible defaults without overwriting what an administrator set', function () {
        $custom = Fx::type('INSTALL');
        $custom->update(['evidence_rules' => ['finish' => ['photo_after' => 3]]]);

        $this->seed(TicketEvidenceRulesSeeder::class);

        expect($custom->fresh()->evidence_rules)->toBe(['finish' => ['photo_after' => 3]])
            ->and(Fx::type('PULLOUT')->evidence_rules['finish'])->toHaveKeys(['photo_before', 'photo_after', 'signature_merchant']);
    });
});

describe('the technician app', function () {
    function actAsTech(object $user): void
    {
        app('auth')->forgetGuards();
        Sanctum::actingAs($user, ['mobile']);
    }

    function upload(object $test, string $url, array $fields = [], ?string $bytes = null)
    {
        return $test->post($url, ['file' => Img::upload($bytes ?? Img::jpeg()), 'kind' => 'photo_before', ...$fields], ['Accept' => 'application/json']);
    }

    it('tells the phone what each result needs and how much is already attached', function () {
        attach($this, AttachmentKind::PhotoBefore);
        actAsTech($this->tech);

        $this->getJson(EVIDENCE_TICKETS."/{$this->ticket->ulid}")
            ->assertOk()
            ->assertJsonPath('data.evidence.finish.photo_before.required', 1)
            ->assertJsonPath('data.evidence.finish.photo_before.have', 1)
            ->assertJsonPath('data.evidence.finish.photo_after.have', 0)
            ->assertJsonPath('data.evidence.pending.photo_evidence.required', 1);
    });

    it('uploads a photo and answers with where to fetch it', function () {
        actAsTech($this->tech);

        $response = upload($this, EVIDENCE_TICKETS."/{$this->ticket->ulid}/attachments", ['latitude' => -6.9, 'longitude' => 107.6, 'captured_at' => now()->subMinute()->toIso8601String()])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'photo_before')
            ->assertJsonPath('data.mime', 'image/jpeg');

        expect($response->json('data.url'))->toContain('/api/mobile/v1/attachments/')
            ->and(Attachment::query()->sole()->uploaded_by)->toBe($this->tech->id);
    });

    it('answers a retried upload with the original and does not duplicate it', function () {
        actAsTech($this->tech);
        $uuid = (string) Str::uuid();
        $bytes = Img::jpeg();
        $url = EVIDENCE_TICKETS."/{$this->ticket->ulid}/attachments";

        $first = upload($this, $url, ['client_uuid' => $uuid], $bytes)->assertCreated();
        $second = upload($this, $url, ['client_uuid' => $uuid], $bytes)->assertOk();

        expect($second->json('data.id'))->toBe($first->json('data.id'))
            ->and(Attachment::query()->count())->toBe(1);
    });

    it('lists my files for the ticket and lets me withdraw one before closing', function () {
        actAsTech($this->tech);
        $url = EVIDENCE_TICKETS."/{$this->ticket->ulid}/attachments";
        $id = upload($this, $url)->json('data.id');

        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

        $this->deleteJson("/api/mobile/v1/attachments/{$id}")->assertNoContent();
        $this->getJson($url)->assertJsonCount(0, 'data');
    });

    it('refuses files that are not images with a clear message', function () {
        actAsTech($this->tech);

        upload($this, EVIDENCE_TICKETS."/{$this->ticket->ulid}/attachments", [], 'this is plain text pretending to be a photo')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        expect(Attachment::query()->count())->toBe(0);
    });

    it('validates the request', function () {
        actAsTech($this->tech);
        $url = EVIDENCE_TICKETS."/{$this->ticket->ulid}/attachments";

        $this->post($url, [], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['file', 'kind']);
        upload($this, $url, ['kind' => 'ba_pdf'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        upload($this, $url, ['kind' => 'selfie'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        upload($this, $url, ['latitude' => -6.9])->assertUnprocessable()->assertJsonValidationErrors('longitude');
        upload($this, $url, ['client_uuid' => 'not-a-uuid'])->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
    });

    it('refuses a file larger than allowed before looking at it', function () {
        actAsTech($this->tech);

        $this->post(EVIDENCE_TICKETS."/{$this->ticket->ulid}/attachments", [
            'file' => UploadedFile::fake()->create('big.jpg', 9000, 'image/jpeg'), 'kind' => 'photo_before',
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
    });

    it('hides a ticket that is not mine and refuses the wrong moment with a stable code', function () {
        actAsTech(Fx::technician());
        upload($this, EVIDENCE_TICKETS."/{$this->ticket->ulid}/attachments")->assertNotFound();
        $this->getJson(EVIDENCE_TICKETS."/{$this->ticket->ulid}/attachments")->assertNotFound();

        $mine = Fx::assigned(technician: $other = Fx::technician());
        actAsTech($other);
        upload($this, EVIDENCE_TICKETS."/{$mine->ulid}/attachments")->assertForbidden()->assertJsonPath('code', 'cannot_attach');
    });

    it('will not let someone else withdraw my file', function () {
        actAsTech($this->tech);
        $id = upload($this, EVIDENCE_TICKETS."/{$this->ticket->ulid}/attachments")->json('data.id');

        actAsTech(Fx::technician());
        $this->deleteJson("/api/mobile/v1/attachments/{$id}")->assertForbidden()->assertJsonPath('code', 'cannot_delete');
    });

    it('needs a signed-in technician', function () {
        $this->post(EVIDENCE_TICKETS."/{$this->ticket->ulid}/attachments", [], ['Accept' => 'application/json'])->assertUnauthorized();
    });

    it('lets the whole offline sequence run in order: photos first, then close', function () {
        actAsTech($this->tech);
        $url = EVIDENCE_TICKETS."/{$this->ticket->ulid}";

        upload($this, "{$url}/attachments", ['kind' => 'photo_before'])->assertCreated();
        upload($this, "{$url}/attachments", ['kind' => 'photo_after'])->assertCreated();
        upload($this, "{$url}/attachments", ['kind' => 'signature_merchant'], Img::transparentPng())->assertCreated();

        $this->postJson("{$url}/close", ['result' => 'finish'])->assertOk()->assertJsonPath('data.status', 'finished');
    });

    it('rejects the close with the missing items listed when the phone sent it too early', function () {
        actAsTech($this->tech);

        $this->postJson(EVIDENCE_TICKETS."/{$this->ticket->ulid}/close", ['result' => 'finish'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachments');
    });
});

describe('verifying stored files', function () {
    it('confirms everything is intact', function () {
        attach($this, AttachmentKind::PhotoBefore);

        $this->artisan('document:verify')->expectsOutputToContain('semuanya utuh')->assertSuccessful();
    });

    it('catches a file that was altered after saving', function () {
        $attachment = attach($this, AttachmentKind::PhotoBefore);
        Storage::disk('attachments')->put($attachment->path, 'tampered');

        $this->artisan('document:verify')->expectsOutputToContain('berubah')->assertFailed();
    });

    it('catches a file that has gone missing', function () {
        $attachment = attach($this, AttachmentKind::PhotoBefore);
        Storage::disk('attachments')->delete($attachment->path);

        $this->artisan('document:verify')->expectsOutputToContain('hilang')->assertFailed();
    });

    it('checks withdrawn files only when asked to', function () {
        $attachment = attach($this, AttachmentKind::PhotoBefore);
        app(DeleteAttachment::class)->handle($attachment, $this->tech);
        Storage::disk('attachments')->delete($attachment->path);

        $this->artisan('document:verify')->assertSuccessful();
        $this->artisan('document:verify --with-deleted')->assertFailed();
    });
});
