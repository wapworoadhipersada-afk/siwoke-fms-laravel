<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Modules\Document\Application\Actions\DeleteAttachment;
use Modules\Document\Application\Actions\StoreAttachment;
use Modules\Document\Domain\Enums\AttachmentKind;
use Modules\Document\Domain\Models\Attachment;
use Modules\Document\Tests\Support\ImageFixtures as Img;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Master\Tests\Support\MasterFixtures;
use Modules\Ticket\Application\Actions\CloseVisit;
use Modules\Ticket\Database\Seeders\TicketCatalogSeeder;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Tests\Support\TicketFixtures as Fx;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('attachments');
    $this->seed([RolesAndPermissionsSeeder::class, TicketCatalogSeeder::class]);
    $this->tech = Fx::technician();
    $this->ticket = Fx::handling(technician: $this->tech);
    $this->visit = $this->ticket->visits()->sole();
});

function store(object $test, string $bytes = '', AttachmentKind $kind = AttachmentKind::PhotoBefore, ?object $user = null, array $extra = []): Attachment
{
    return app(StoreAttachment::class)->handle(
        $test->visit,
        $user ?? $test->tech,
        Img::upload($bytes === '' ? Img::jpeg() : $bytes, $extra['name'] ?? 'foto.jpg'),
        $kind,
        $extra['capturedAt'] ?? null,
        $extra['latitude'] ?? null,
        $extra['longitude'] ?? null,
        $extra['deviceId'] ?? null,
        $extra['clientUuid'] ?? null,
    );
}

function storedFiles(): array
{
    return Storage::disk('attachments')->allFiles();
}

describe('storing a file', function () {
    it('keeps a cleaned copy under a random name and a thumbnail, and records the evidence trail', function () {
        $upload = Img::jpeg(1600, 1200);
        $capturedAt = now()->subMinutes(20);

        $attachment = store($this, $upload, extra: ['capturedAt' => $capturedAt, 'latitude' => -6.9175, 'longitude' => 107.6191, 'deviceId' => 7]);

        $disk = Storage::disk('attachments');
        $stored = $disk->get($attachment->path);

        expect($attachment->kind)->toBe(AttachmentKind::PhotoBefore)
            ->and($attachment->attachable_type)->toBe('ticket_visit')
            ->and($attachment->attachable_id)->toBe($this->visit->id)
            ->and($attachment->uploaded_by)->toBe($this->tech->id)
            ->and($attachment->mime)->toBe('image/jpeg')
            ->and($attachment->size)->toBe(strlen($stored))
            ->and([$attachment->width, $attachment->height])->toBe([1600, 1200])
            ->and($attachment->sha256)->toBe(hash('sha256', $stored))
            ->and($attachment->source_sha256)->toBe(hash('sha256', $upload))
            ->and($attachment->captured_at->toDateTimeString())->toBe($capturedAt->toDateTimeString())
            ->and($attachment->latitude)->toBe('-6.9175000')
            ->and($attachment->device_id)->toBe(7)
            ->and($attachment->path)->toMatch('#^attachments/\d{4}/\d{2}/[0-9a-z]{26}\.jpg$#i')
            ->and($disk->exists($attachment->thumbnail_path))->toBeTrue()
            ->and($disk->size($attachment->thumbnail_path))->toBeLessThan($disk->size($attachment->path));
    });

    it('never lets the uploader choose where the file goes', function (string $name, ?string $shown) {
        $attachment = store($this, extra: ['name' => $name]);

        expect($attachment->path)->not->toContain('passwd')->not->toContain('..')->not->toContain('script')
            ->and($attachment->original_name)->toBe($shown);
    })->with([
        'path traversal' => ['../../etc/passwd.jpg', 'passwd.jpg'],
        'windows traversal' => ['..\\..\\boot\\evil.jpg', 'evil.jpg'],
        'markup in the name' => ['<script>alert(1)</script>.jpg', 'script.jpg'],
        'only symbols' => ['***', null],
    ]);

    it('stores the cleaned image, not the hostile upload', function () {
        $attachment = store($this, Img::hostileJpeg(6), extra: ['name' => 'shell.php.jpg']);
        $stored = Storage::disk('attachments')->get($attachment->path);

        expect($stored)->not->toContain('GPS-SECRET-LOCATION-DATA')->not->toContain(Img::phpPayload())
            ->and($attachment->path)->not->toContain('shell')
            ->and($attachment->path)->toEndWith('.jpg');
    });

    it('refuses a file that is not an image and leaves nothing behind', function () {
        expect(fn () => store($this, 'this is plain text pretending to be a photo', extra: ['name' => 'foto.jpg']))->toThrow(ValidationException::class, 'JPG, PNG, atau WebP')
            ->and(storedFiles())->toBe([])
            ->and(Attachment::query()->count())->toBe(0);
    });

    it('refuses an oversized upload', function () {
        config(['documents.max_upload_kilobytes' => 10]);

        store($this, Img::jpeg(1500, 1500));
    })->throws(ValidationException::class, 'Ukuran file');

    it('refuses the same file twice for one visit, but allows it again once the first was withdrawn', function () {
        $bytes = Img::jpeg();
        $first = store($this, $bytes);

        expect(fn () => store($this, $bytes))->toThrow(ValidationException::class, 'sudah pernah diunggah');

        app(DeleteAttachment::class)->handle($first, $this->tech);

        expect(store($this, $bytes)->exists)->toBeTrue();
    });

    it('treats the same bytes as a repeat even under another kind', function () {
        $bytes = Img::jpeg();
        store($this, $bytes);

        // Same bytes, different kind: still a repeat of an upload on this visit.
        expect(fn () => store($this, $bytes, AttachmentKind::PhotoAfter))->toThrow(ValidationException::class);
    });

    it('limits the number of photos of one kind', function () {
        foreach (range(1, 10) as $ignored) {
            store($this, Img::jpeg());
        }

        expect(fn () => store($this, Img::jpeg()))->toThrow(ValidationException::class, 'Maksimal 10')
            ->and(Attachment::query()->count())->toBe(10);

        // Another kind has its own allowance.
        expect(store($this, Img::jpeg(), AttachmentKind::PhotoAfter)->exists)->toBeTrue();
    });

    it('keeps one signature, replacing the earlier one on record', function () {
        $first = store($this, Img::transparentPng(), AttachmentKind::SignatureMerchant);
        $second = store($this, Img::transparentPng(700, 350), AttachmentKind::SignatureMerchant);

        expect(Attachment::query()->find($first->id))->toBeNull()
            ->and(Attachment::withTrashed()->find($first->id)->trashed())->toBeTrue()
            ->and($this->visit->attachments()->where('kind', 'signature_merchant')->pluck('id')->all())->toBe([$second->id])
            ->and($second->mime)->toBe('image/png');
    });

    it('does not let the system-made kinds be uploaded', function () {
        store($this, kind: AttachmentKind::BeritaAcara);
    })->throws(ValidationException::class, 'tidak dapat diunggah');

    it('rejects an impossible capture time', function (string $when) {
        store($this, extra: ['capturedAt' => $when === 'future' ? now()->addHour() : now()->subDays(8)]);
    })->with(['future', 'ancient'])->throws(ValidationException::class);

    it('accepts a photo taken offline some hours ago', function () {
        expect(store($this, extra: ['capturedAt' => now()->subHours(6)])->exists)->toBeTrue();
    });

    it('writes an audit entry saying who added what', function () {
        $attachment = store($this);

        $activity = Activity::query()->where('log_name', 'document')->where('event', 'created')->sole();

        expect($activity->subject_id)->toBe($attachment->id)
            ->and($activity->properties['ip'])->not->toBeNull();
    });
});

describe('a retried upload', function () {
    it('returns the original and stores nothing new', function () {
        $uuid = (string) Str::uuid();
        $bytes = Img::jpeg();

        $first = store($this, $bytes, extra: ['clientUuid' => $uuid]);
        $filesAfterFirst = count(storedFiles());
        $again = store($this, $bytes, extra: ['clientUuid' => $uuid]);

        expect($again->id)->toBe($first->id)
            ->and(Attachment::query()->count())->toBe(1)
            ->and(count(storedFiles()))->toBe($filesAfterFirst);
    });

    it('does not let a client id from one visit be reused on another', function () {
        $uuid = (string) Str::uuid();
        store($this, extra: ['clientUuid' => $uuid]);

        $otherTech = Fx::technician();
        $otherTicket = Fx::handling(technician: $otherTech);

        app(StoreAttachment::class)->handle($otherTicket->visits()->sole(), $otherTech, Img::upload(Img::jpeg()), AttachmentKind::PhotoBefore, clientUuid: $uuid);
    })->throws(ValidationException::class, 'sudah dipakai');
});

describe('who may add files', function () {
    it('refuses a technician who does not have the visit', function () {
        store($this, user: Fx::technician());
    })->throws(AuthorizationException::class);

    it('refuses an office user, unless they hold the close-override permission', function () {
        expect(fn () => store($this, user: Fx::dispatcher()))->toThrow(AuthorizationException::class);

        $override = Fx::dispatcher()->givePermissionTo('ticket.close.override');

        expect(store($this, user: $override)->uploaded_by)->toBe($override->id);
    });

    it('refuses once the job was only assigned, not yet accepted', function () {
        $assigned = Fx::assigned(technician: $tech = Fx::technician());

        app(StoreAttachment::class)->handle($assigned->visits()->sole(), $tech, Img::upload(Img::jpeg()), AttachmentKind::PhotoBefore);
    })->throws(AuthorizationException::class);

    it('refuses after the visit has been closed', function () {
        app(CloseVisit::class)->handle($this->ticket, $this->tech, VisitOutcome::Finish);

        $this->visit->refresh();

        store($this);
    })->throws(AuthorizationException::class);
});

describe('withdrawing a file', function () {
    it('marks it deleted but keeps the row and the file, and audits it', function () {
        $attachment = store($this);

        app(DeleteAttachment::class)->handle($attachment, $this->tech);

        expect(Attachment::query()->count())->toBe(0)
            ->and(Attachment::withTrashed()->count())->toBe(1)
            ->and(Storage::disk('attachments')->exists($attachment->path))->toBeTrue()
            ->and(Activity::query()->where('log_name', 'document')->where('event', 'deleted')->count())->toBe(1);
    });

    it('is only for the person who sent it', function () {
        $attachment = store($this);

        app(DeleteAttachment::class)->handle($attachment, Fx::dispatcher()->givePermissionTo('ticket.close.override'));
    })->throws(AuthorizationException::class);

    it('is no longer possible once the visit is closed', function () {
        $attachment = store($this);
        app(CloseVisit::class)->handle($this->ticket, $this->tech, VisitOutcome::Finish);

        app(DeleteAttachment::class)->handle($attachment->fresh(), $this->tech);
    })->throws(AuthorizationException::class);
});

describe('reading a file', function () {
    beforeEach(function () {
        $this->attachment = store($this);
    });

    it('serves the image to the technician with headers that keep a browser from misreading it', function () {
        $response = $this->actingAs($this->tech)->get("/attachments/{$this->attachment->ulid}");

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        expect($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
    });

    it('serves the thumbnail as a JPEG', function () {
        $this->actingAs($this->tech)->get("/attachments/{$this->attachment->ulid}/thumbnail")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    });

    it('serves it to office staff who can see the ticket, and to no one else', function () {
        $url = "/attachments/{$this->attachment->ulid}";

        $this->actingAs(Fx::dispatcher())->get($url)->assertOk();

        $this->actingAs(Fx::technician())->get($url)->assertNotFound();
        $this->actingAs(Fx::scopedDispatcher([MasterFixtures::chain()['province']]))->get($url)->assertNotFound();
    });

    it('asks guests to log in', function () {
        $this->get("/attachments/{$this->attachment->ulid}")->assertRedirect(route('login'));
    });

    it('answers 404 for a withdrawn file, a missing file, and an unknown id', function () {
        $this->actingAs(Fx::dispatcher());

        $this->attachment->delete();
        $this->get("/attachments/{$this->attachment->ulid}")->assertNotFound();

        $this->visit->refresh();
        $kept = store($this, Img::jpeg(900, 700), AttachmentKind::PhotoAfter);
        Storage::disk('attachments')->delete($kept->path);
        $this->get("/attachments/{$kept->ulid}")->assertNotFound();

        $this->get('/attachments/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
    });

    it('serves the mobile download only to the owner of the visit', function () {
        $url = "/api/mobile/v1/attachments/{$this->attachment->ulid}";

        app('auth')->forgetGuards();
        Sanctum::actingAs($this->tech, ['mobile']);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        app('auth')->forgetGuards();
        Sanctum::actingAs(Fx::technician(), ['mobile']);
        $this->getJson($url)->assertNotFound();
    });

    it('requires a token on the mobile download', function () {
        $this->getJson("/api/mobile/v1/attachments/{$this->attachment->ulid}")->assertUnauthorized();
    });
});

describe('the ticket page', function () {
    it('shows each visit its own evidence, with links that stay inside the app', function () {
        store($this);
        store($this, kind: AttachmentKind::SignatureMerchant, bytes: Img::transparentPng());

        $this->actingAs(Fx::dispatcher())->get("/tickets/{$this->ticket->ulid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('attachments', 2)
                ->where('attachments.0.kind', 'photo_before')
                ->where('attachments.0.visit_no', 1)
                ->where('attachments.0.uploader', $this->tech->name)
                ->where('attachments.0.url', fn (string $url) => str_starts_with($url, '/attachments/'))
                ->where('attachments.1.kind', 'signature_merchant'));
    });

    it('lists nothing for a withdrawn file', function () {
        $attachment = store($this);
        app(DeleteAttachment::class)->handle($attachment, $this->tech);

        $this->actingAs(Fx::dispatcher())->get("/tickets/{$this->ticket->ulid}")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('attachments', 0));
    });
});
