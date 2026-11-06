<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\Master\Application\Actions\ImportMerchantDocument;
use Modules\Master\Application\Actions\SaveMerchantDocument;
use Modules\Master\Application\Actions\SyncUserVendors;
use Modules\Master\Domain\Enums\LocationType;
use Modules\Master\Domain\Models\Merchant;
use Modules\Master\Domain\Models\MerchantDocument;
use Modules\Master\Domain\Models\MerchantDocumentLine;
use Modules\Master\Domain\Models\MerchantSite;
use Modules\Master\Domain\Models\Terminal;
use Modules\Master\Tests\Support\MasterFixtures as Fx;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->vendor = Fx::vendor('BRI');
    $this->chain = Fx::chain($this->vendor);
    $this->admin = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);
});

function docRow(array $over = []): array
{
    return array_merge([
        'merchant_name' => 'Toko Madura Bekasi',
        'tid' => '145678',
        'mid' => '',
        'address' => 'Jl. Contoh 1',
        'province' => '',
        'service_point' => '',
        'area' => test()->chain['area']->name,
        'tid_note' => 'EDC baru',
    ], $over);
}

function saveDoc(array $rows, string $number = 'B.38121-MMS-JKE/IJE/10/2024'): MerchantDocument
{
    return app(SaveMerchantDocument::class)->handle(test()->vendor, $number, '2026-11-01', null, $rows, test()->admin);
}

describe('recording a document', function () {
    it('keeps the number, date and every line, checked but not yet imported', function () {
        $document = saveDoc([docRow(), docRow(['merchant_name' => 'Warung Sari', 'tid' => '145679'])]);

        expect($document->status)->toBe('draft')
            ->and($document->lines)->toHaveCount(2)
            ->and($document->lines->pluck('status')->unique()->all())->toBe(['pending'])
            ->and($document->lines->first()->location_id)->toBe($this->chain['area']->id)
            ->and(Terminal::query()->count())->toBe(0)
            ->and(Merchant::query()->count())->toBe(0);
    });

    it('marks a line whose area cannot be found, with the reason', function () {
        $document = saveDoc([docRow(['area' => 'Area Tidak Ada'])]);

        expect($document->lines->first()->status)->toBe('error')
            ->and($document->lines->first()->error)->toContain('tidak ditemukan');
    });

    it('marks the second line when a TID is repeated inside the document', function () {
        $document = saveDoc([docRow(), docRow(['merchant_name' => 'Toko Lain'])]);

        expect($document->lines->pluck('status')->all())->toBe(['pending', 'error'])
            ->and($document->lines->last()->error)->toContain('sudah terdaftar');
    });

    it('marks a TID that already exists for the vendor, but not for another vendor', function () {
        Fx::terminalIn($this->vendor, $this->chain['area']);
        $existing = Terminal::query()->firstOrFail();

        $document = saveDoc([docRow(['tid' => $existing->tid])]);
        expect($document->lines->first()->status)->toBe('error');

        $other = Fx::vendor('BCA');
        $otherChain = Fx::chain($other);
        $second = app(SaveMerchantDocument::class)->handle($other, 'DOC-2', '2026-11-01', null, [docRow(['tid' => $existing->tid, 'area' => $otherChain['area']->name])], $this->admin);
        expect($second->lines->first()->status)->toBe('pending');
    });

    it('wants the service point when an area name exists under two of them', function () {
        $otherChain = Fx::chain($this->vendor);
        $otherChain['area']->forceFill(['name' => $this->chain['area']->name])->save();

        $document = saveDoc([docRow(), docRow(['tid' => '999', 'service_point' => $this->chain['service_point']->name])]);

        expect($document->lines->first()->error)->toContain('lebih dari satu')
            ->and($document->lines->last()->status)->toBe('pending');
    });

    it('refuses an empty document and a repeated document number', function () {
        expect(fn () => saveDoc([]))->toThrow(ValidationException::class);

        saveDoc([docRow()]);

        expect(fn () => saveDoc([docRow(['tid' => '2'])]))->toThrow(ValidationException::class);
    });
});

describe('importing', function () {
    it('creates the merchant, its site and the TID together, and links them to the document', function () {
        $document = saveDoc([docRow()]);

        $result = app(ImportMerchantDocument::class)->handle($document, $this->admin);

        $terminal = Terminal::query()->firstOrFail();

        expect($result)->toBe(['imported' => 1, 'failed' => 0])
            ->and($terminal->tid)->toBe('145678')
            ->and($terminal->note)->toBe('EDC baru')
            ->and($terminal->merchant_document_id)->toBe($document->id)
            ->and($terminal->merchant->name)->toBe('Toko Madura Bekasi')
            ->and($terminal->site->location_id)->toBe($this->chain['area']->id)
            ->and($document->fresh()->status)->toBe('imported')
            ->and($document->lines()->first()->terminal_id)->toBe($terminal->id);
    });

    it('keeps one merchant when several TIDs share a MID', function () {
        $document = saveDoc([docRow(['mid' => 'M-77']), docRow(['tid' => '145679', 'mid' => 'm-77'])]);

        app(ImportMerchantDocument::class)->handle($document, $this->admin);

        expect(Merchant::query()->count())->toBe(1)
            ->and(MerchantSite::query()->count())->toBe(1)
            ->and(Terminal::query()->count())->toBe(2);
    });

    it('lets good lines in even when another line is wrong', function () {
        $document = saveDoc([docRow(), docRow(['tid' => '2', 'area' => 'Nowhere'])]);

        $result = app(ImportMerchantDocument::class)->handle($document, $this->admin);

        expect($result)->toBe(['imported' => 1, 'failed' => 0])
            ->and(Terminal::query()->count())->toBe(1)
            ->and($document->lines()->where('status', 'error')->count())->toBe(1);
    });

    it('imports a line again after its area has been added', function () {
        $document = saveDoc([docRow(['area' => 'Area Baru'])]);

        expect($document->lines->first()->status)->toBe('error');

        Fx::location(LocationType::Area, $this->chain['service_point'], 'NEW-1')->forceFill(['name' => 'Area Baru'])->save();

        app(ImportMerchantDocument::class)->recheck($document);
        app(ImportMerchantDocument::class)->handle($document, $this->admin);

        expect(Terminal::query()->count())->toBe(1)
            ->and($document->fresh()->status)->toBe('imported');
    });
});

describe('the screens', function () {
    it('uploads a CSV, shows the checked lines and imports them', function () {
        $csv = "Nama Merchant,TID,MID,Alamat,Provinsi,Service Point,Area,Keterangan TID\n"
            ."Toko Madura Bekasi,145678,,Jl. A,,,{$this->chain['area']->name},baru\n"
            ."Warung Sari,145679,M-2,Jl. B,,,{$this->chain['area']->name},\n";

        $response = $this->actingAs($this->admin)->post(route('master.documents.store'), [
            'vendor' => $this->vendor->ulid,
            'document_number' => 'B.38121-MMS-JKE/IJE/10/2024',
            'received_on' => '2026-11-01',
            'file' => UploadedFile::fake()->createWithContent('lampiran.csv', $csv),
        ]);

        $document = MerchantDocument::query()->firstOrFail();
        $response->assertRedirect(route('master.documents.show', $document));

        $this->get(route('master.documents.show', $document))->assertOk()
            ->assertInertia(fn ($page) => $page->component('master/documents/Show')->where('counts.pending', 2)->has('lines', 2));

        $this->post(route('master.documents.import', $document))->assertRedirect();

        expect(Terminal::query()->pluck('tid')->all())->toEqualCanonicalizing(['145678', '145679']);

        $this->get(route('master.documents.index'))->assertInertia(fn ($page) => $page->has('documents.data', 1)->where('documents.data.0.imported', 2));
    });

    it('accepts lines typed into the form', function () {
        $this->actingAs($this->admin)->post(route('master.documents.store'), [
            'vendor' => $this->vendor->ulid,
            'document_number' => 'MANUAL-1',
            'received_on' => '2026-11-01',
            'lines' => [['merchant_name' => 'Toko A', 'tid' => 'T-1', 'area' => $this->chain['area']->name]],
        ])->assertRedirect();

        expect(MerchantDocumentLine::query()->count())->toBe(1);
    });

    it('rejects a file that is not a spreadsheet', function () {
        $this->actingAs($this->admin)->post(route('master.documents.store'), [
            'vendor' => $this->vendor->ulid,
            'document_number' => 'BAD-1',
            'received_on' => '2026-11-01',
            'file' => UploadedFile::fake()->createWithContent('x.exe', 'MZ'),
        ])->assertSessionHasErrors('file');

        expect(MerchantDocument::query()->count())->toBe(0);
    });

    it('does not let someone limited to another vendor see or create the document', function () {
        $document = saveDoc([docRow()]);
        $other = Fx::vendor('BCA');
        $user = User::factory()->create()->assignRole(RoleName::OperationalAdmin->value);
        app(SyncUserVendors::class)->handle($user, [$other->id]);

        $this->actingAs($user)->get(route('master.documents.show', $document))->assertNotFound();
        $this->post(route('master.documents.store'), ['vendor' => $this->vendor->ulid, 'document_number' => 'X', 'received_on' => '2026-11-01', 'lines' => [['merchant_name' => 'a', 'tid' => 'b', 'area' => 'c']]])
            ->assertSessionHasErrors('vendor');
    });

    it('lets a draft be deleted but not a document that already brought in TIDs', function () {
        $draft = saveDoc([docRow(['area' => 'Nowhere'])], 'DRAFT-1');
        $this->actingAs($this->admin)->delete(route('master.documents.destroy', $draft))->assertRedirect(route('master.documents.index'));
        expect(MerchantDocument::query()->count())->toBe(0);

        $done = saveDoc([docRow()], 'DONE-1');
        app(ImportMerchantDocument::class)->handle($done, $this->admin);
        $this->delete(route('master.documents.destroy', $done))->assertSessionHasErrors('document');
        expect(MerchantDocument::query()->count())->toBe(1);
    });

    it('keeps the screens away from people without the permission', function () {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->get(route('master.documents.index'))->assertForbidden();
        $this->post(route('master.documents.store'), [])->assertForbidden();
    });
});
