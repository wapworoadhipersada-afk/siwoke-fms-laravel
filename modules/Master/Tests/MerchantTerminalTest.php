<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Master\Application\Actions\CreateMerchantSite;
use Modules\Master\Application\Actions\CreateTerminal;
use Modules\Master\Application\Actions\CreateVendor;
use Modules\Master\Application\Actions\DeleteMerchant;
use Modules\Master\Application\Actions\DeleteMerchantSite;
use Modules\Master\Application\Actions\DeleteVendor;
use Modules\Master\Application\Actions\UpdateMerchantSite;
use Modules\Master\Application\Actions\UpdateTerminal;
use Modules\Master\Domain\Enums\LocationType;
use Modules\Master\Domain\Enums\TerminalStatus;
use Modules\Master\Domain\Models\MerchantSite;
use Modules\Master\Domain\Models\Terminal;
use Modules\Master\Tests\Support\MasterFixtures as Fx;
use Spatie\Activitylog\Models\Activity;

describe('vendors', function () {
    it('normalises the code and keeps it unique', function () {
        $vendor = app(CreateVendor::class)->handle(['code' => ' bri ', 'name' => 'Bank BRI']);

        expect($vendor->code)->toBe('BRI')
            ->and($vendor->ulid)->toHaveLength(26);

        app(CreateVendor::class)->handle(['code' => 'Bri', 'name' => 'Duplicate']);
    })->throws(ValidationException::class);

    it('cannot be deleted while it owns merchants', function () {
        $vendor = Fx::vendor();
        Fx::merchant($vendor);

        app(DeleteVendor::class)->handle($vendor);
    })->throws(ValidationException::class);

    it('can be deleted when empty', function () {
        $vendor = Fx::vendor();

        app(DeleteVendor::class)->handle($vendor);

        expect($vendor->fresh()->trashed())->toBeTrue();
    });
});

describe('merchants and sites', function () {
    it('keeps merchant codes unique within a vendor only', function () {
        $bri = Fx::vendor('BRI');
        $cimb = Fx::vendor('CIMB');

        Fx::merchant($bri, 'M001');
        Fx::merchant($cimb, 'M001');

        expect(DB::table('merchants')->where('merchant_code', 'M001')->count())->toBe(2);

        Fx::merchant($bri, 'm001');
    })->throws(ValidationException::class);

    it('attaches a site only to a managed area', function (string $level) {
        $chain = Fx::chain();

        app(CreateMerchantSite::class)->handle([
            'merchant_id' => Fx::merchant(Fx::vendor())->id,
            'location_id' => $chain[$level]->id,
            'address' => 'Jl. Salah Level',
        ]);
    })->with(['province', 'service_point'])->throws(ValidationException::class, 'Area Kelolaan');

    it('moves a site to another service point', function () {
        $first = Fx::chain()['area'];
        $second = Fx::chain()['area'];
        $site = Fx::site(Fx::merchant(Fx::vendor()), $first);

        app(UpdateMerchantSite::class)->handle($site, ['location_id' => $second->id]);

        expect($site->fresh()->location_id)->toBe($second->id);
    });

    it('rejects out-of-range coordinates at the database level', function () {
        $site = Fx::site(Fx::merchant(Fx::vendor()), Fx::chain()['area']);

        MerchantSite::query()->whereKey($site->id)->update(['latitude' => 123.4]);
    })->throws(QueryException::class);

    it('soft-deletes its sites together with the merchant', function () {
        $merchant = Fx::merchant(Fx::vendor());
        $site = Fx::site($merchant, Fx::chain()['area']);

        app(DeleteMerchant::class)->handle($merchant);

        expect($merchant->fresh()->trashed())->toBeTrue()
            ->and($site->fresh()->trashed())->toBeTrue();
    });

    it('cannot delete a merchant or site that still has a terminal', function () {
        $site = Fx::site($merchant = Fx::merchant(Fx::vendor()), Fx::chain()['area']);
        Fx::terminal($site);

        expect(fn () => app(DeleteMerchant::class)->handle($merchant))->toThrow(ValidationException::class)
            ->and(fn () => app(DeleteMerchantSite::class)->handle($site))->toThrow(ValidationException::class);
    });
});

describe('terminals', function () {
    it('derives vendor and merchant from the site', function () {
        $vendor = Fx::vendor();
        $merchant = Fx::merchant($vendor);
        $site = Fx::site($merchant, Fx::chain()['area']);

        $terminal = Fx::terminal($site, ' tid001 ');

        expect($terminal->tid)->toBe('TID001')
            ->and($terminal->vendor_id)->toBe($vendor->id)
            ->and($terminal->merchant_id)->toBe($merchant->id)
            ->and($terminal->merchant_site_id)->toBe($site->id)
            ->and($terminal->status)->toBe(TerminalStatus::Active);
    });

    it('allows the same TID at different vendors but not twice at one', function () {
        $sp = Fx::chain()['area'];
        Fx::terminalIn(Fx::vendor('BRI'), $sp)->update(['tid' => 'TID777']);
        $cimbTerminal = Fx::terminalIn(Fx::vendor('CIMB'), $sp);

        $cimbTerminal->update(['tid' => 'TID777']);

        expect(Terminal::query()->where('tid', 'TID777')->count())->toBe(2);

        $bri = Terminal::query()->where('tid', 'TID777')->first();
        Fx::terminal(Fx::site(Fx::merchant($bri->vendor), $sp), 'tid777');
    })->throws(ValidationException::class);

    it('frees the TID once the terminal is soft-deleted', function () {
        $site = Fx::site(Fx::merchant(Fx::vendor()), Fx::chain()['area']);
        $terminal = Fx::terminal($site, 'TID900');
        $terminal->delete();

        $again = Fx::terminal($site, 'TID900');

        expect($again->exists)->toBeTrue();
    });

    it('moves to a site of another merchant of the same vendor', function () {
        $vendor = Fx::vendor();
        $sp = Fx::chain()['area'];
        $terminal = Fx::terminalIn($vendor, $sp);
        $otherMerchant = Fx::merchant($vendor);
        $otherSite = Fx::site($otherMerchant, $sp);

        app(UpdateTerminal::class)->handle($terminal, ['merchant_site_id' => $otherSite->id]);

        expect($terminal->fresh()->merchant_id)->toBe($otherMerchant->id)
            ->and($terminal->fresh()->merchant_site_id)->toBe($otherSite->id);
    });

    it('refuses to move to a site that belongs to another vendor', function () {
        $sp = Fx::chain()['area'];
        $terminal = Fx::terminalIn(Fx::vendor('BRI'), $sp);
        $foreignSite = Fx::site(Fx::merchant(Fx::vendor('CIMB')), $sp);

        app(UpdateTerminal::class)->handle($terminal, ['merchant_site_id' => $foreignSite->id]);
    })->throws(ValidationException::class, 'vendor lain');

    it('changes status', function () {
        $terminal = Fx::terminalIn(Fx::vendor(), Fx::chain()['area']);

        app(UpdateTerminal::class)->handle($terminal, ['status' => TerminalStatus::Removed]);

        expect($terminal->fresh()->status)->toBe(TerminalStatus::Removed);
    });

    it('cannot be filed under a merchant of another vendor, even by raw SQL', function () {
        $sp = Fx::chain()['area'];
        $bri = Fx::vendor('BRI');
        $foreignMerchant = Fx::merchant(Fx::vendor('CIMB'));
        $foreignSite = Fx::site($foreignMerchant, $sp);

        DB::table('terminals')->insert([
            'ulid' => (string) Str::ulid(),
            'vendor_id' => $bri->id,
            'merchant_id' => $foreignMerchant->id,
            'merchant_site_id' => $foreignSite->id,
            'tid' => 'EVIL1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    })->throws(QueryException::class);

    it('cannot point at a site of a different merchant, even by raw SQL', function () {
        $sp = Fx::chain()['area'];
        $vendor = Fx::vendor();
        $merchantA = Fx::merchant($vendor);
        $siteOfB = Fx::site(Fx::merchant($vendor), $sp);

        DB::table('terminals')->insert([
            'ulid' => (string) Str::ulid(),
            'vendor_id' => $vendor->id,
            'merchant_id' => $merchantA->id,
            'merchant_site_id' => $siteOfB->id,
            'tid' => 'EVIL2',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    })->throws(QueryException::class);

    it('rejects an unknown status at the database level', function () {
        $terminal = Fx::terminalIn(Fx::vendor(), Fx::chain()['area']);

        DB::table('terminals')->where('id', $terminal->id)->update(['status' => 'stolen']);
    })->throws(QueryException::class);

    it('rejects a site that does not exist', function () {
        app(CreateTerminal::class)->handle(['merchant_site_id' => 999999, 'tid' => 'TID1']);
    })->throws(ValidationException::class);

    it('audits creation and changes in the master log', function () {
        $terminal = Fx::terminalIn(Fx::vendor(), Fx::chain()['area']);
        app(UpdateTerminal::class)->handle($terminal, ['status' => TerminalStatus::Inactive]);

        $events = Activity::query()
            ->where('log_name', 'master')
            ->where('subject_type', $terminal->getMorphClass())
            ->where('subject_id', $terminal->id)
            ->orderBy('id')
            ->pluck('event')
            ->all();

        expect($events)->toBe(['created', 'updated']);
    });
});

it('creates locations with the type enum available for tests', function () {
    expect(LocationType::Area->depth())->toBe(3)
        ->and(LocationType::Province->parentType())->toBeNull();
});
