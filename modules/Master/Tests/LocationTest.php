<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Master\Application\Actions\CreateLocation;
use Modules\Master\Application\Actions\DeleteLocation;
use Modules\Master\Application\Actions\UpdateLocation;
use Modules\Master\Domain\Enums\LocationType;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Tests\Support\MasterFixtures as Fx;

it('builds the materialized path and depth through all three levels', function () {
    ['province' => $p, 'service_point' => $s, 'area' => $a] = Fx::chain();

    expect($p->fresh()->path)->toBe("/{$p->id}/")
        ->and($p->fresh()->depth)->toBe(1)
        ->and($s->fresh()->path)->toBe("/{$p->id}/{$s->id}/")
        ->and($s->fresh()->depth)->toBe(2)
        ->and($a->fresh()->path)->toBe("/{$p->id}/{$s->id}/{$a->id}/")
        ->and($a->fresh()->depth)->toBe(3);
});

it('selects a whole subtree with one prefix match', function () {
    ['province' => $p, 'service_point' => $s, 'area' => $a] = Fx::chain();
    $other = Fx::chain();

    $ids = Location::query()->where('path', 'like', $s->fresh()->path.'%')->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing([$s->id, $a->id])
        ->and($ids)->not->toContain($p->id, $other['province']->id);
});

it('knows whether it descends from another location', function () {
    ['province' => $p, 'area' => $a] = Fx::chain();

    expect($a->fresh()->isDescendantOf($p->fresh()))->toBeTrue()
        ->and($p->fresh()->isDescendantOf($a->fresh()))->toBeFalse()
        ->and($p->fresh()->isDescendantOf($p->fresh()))->toBeFalse();
});

it('rejects a child under the wrong parent type', function (LocationType $type, ?string $parentLevel) {
    $chain = Fx::chain();
    $parent = $parentLevel === null ? null : $chain[$parentLevel];

    app(CreateLocation::class)->handle([
        'type' => $type, 'parent_id' => $parent?->id, 'code' => 'X1', 'name' => 'X',
    ]);
})->with([
    'area under a province' => [LocationType::Area, 'province'],
    'service point under a service point' => [LocationType::ServicePoint, 'service_point'],
    'service point under an area' => [LocationType::ServicePoint, 'area'],
    'service point without a parent' => [LocationType::ServicePoint, null],
    'area without a parent' => [LocationType::Area, null],
    'province with a parent' => [LocationType::Province, 'province'],
])->throws(ValidationException::class);

it('enforces a province-only root at the database level', function () {
    DB::table('locations')->insert([
        'ulid' => (string) Str::ulid(), 'type' => 'service_point', 'code' => 'ORPHAN', 'name' => 'Orphan',
        'created_at' => now(), 'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('normalises codes and keeps them unique per type, case-insensitively', function () {
    Fx::location(LocationType::Province, null, 'jb');

    expect(Location::query()->where('code', 'JB')->exists())->toBeTrue();

    Fx::location(LocationType::Province, null, 'Jb');
})->throws(ValidationException::class);

it('allows the same code at different levels', function () {
    $province = Fx::location(LocationType::Province, null, '32');
    Fx::location(LocationType::ServicePoint, $province, '32');

    expect(Location::query()->where('code', '32')->count())->toBe(2);
});

it('rewrites the paths of the whole subtree when a node moves', function () {
    ['province' => $oldProvince, 'service_point' => $sp, 'area' => $area] = Fx::chain();
    $newProvince = Fx::location(LocationType::Province);

    app(UpdateLocation::class)->handle($sp, ['parent_id' => $newProvince->id]);

    expect($sp->fresh()->path)->toBe("/{$newProvince->id}/{$sp->id}/")
        ->and($area->fresh()->path)->toBe("/{$newProvince->id}/{$sp->id}/{$area->id}/")
        ->and($area->fresh()->isDescendantOf($newProvince->fresh()))->toBeTrue()
        ->and($area->fresh()->isDescendantOf($oldProvince->fresh()))->toBeFalse();
});

it('refuses to move a node under a parent of the wrong type', function () {
    ['service_point' => $sp, 'area' => $area] = Fx::chain();

    app(UpdateLocation::class)->handle($sp, ['parent_id' => $area->id]);
})->throws(ValidationException::class);

it('updates plain attributes without touching the path', function () {
    ['service_point' => $sp] = Fx::chain();
    $path = $sp->fresh()->path;

    app(UpdateLocation::class)->handle($sp, ['name' => 'SP Bandung', 'is_active' => false]);

    expect($sp->fresh()->name)->toBe('SP Bandung')
        ->and($sp->fresh()->is_active)->toBeFalse()
        ->and($sp->fresh()->path)->toBe($path);
});

it('refuses to delete a location that still has children', function () {
    ['province' => $province] = Fx::chain();

    app(DeleteLocation::class)->handle($province);
})->throws(ValidationException::class);

it('refuses to delete a location used by a merchant site', function () {
    ['area' => $area] = Fx::chain();
    Fx::site(Fx::merchant(Fx::vendor()), $area);

    app(DeleteLocation::class)->handle($area);
})->throws(ValidationException::class);

it('soft-deletes a leaf location and frees its code', function () {
    ['area' => $area] = Fx::chain();
    $code = $area->code;

    app(DeleteLocation::class)->handle($area);

    expect(Location::query()->find($area->id))->toBeNull()
        ->and(Location::withTrashed()->find($area->id))->not->toBeNull();

    Fx::location(LocationType::Area, $area->parent, $code);

    expect(Location::query()->where('code', $code)->count())->toBe(1);
});

describe('vendors', function () {
    it('lets a service point name its vendor and makes the areas under it follow', function () {
        $bri = Fx::vendor('BRI');
        ['service_point' => $sp, 'area' => $area] = Fx::chain($bri);

        expect($sp->fresh()->vendor_id)->toBe($bri->id)->and($area->fresh()->vendor_id)->toBe($bri->id);
    });

    it('treats a service point with no vendor as shared by every vendor', function () {
        ['service_point' => $sp, 'area' => $area] = Fx::chain();

        expect($sp->fresh()->vendor_id)->toBeNull()->and($area->fresh()->vendor_id)->toBeNull();

        Fx::site(Fx::merchant(Fx::vendor('BRI')), $area);
        Fx::site(Fx::merchant(Fx::vendor('CIMB')), $area);

        expect(true)->toBeTrue();
    });

    it('never gives a province a vendor, and ignores a vendor sent for an area', function () {
        $bri = Fx::vendor('BRI');
        $cimb = Fx::vendor('CIMB');
        $province = app(CreateLocation::class)->handle(['type' => LocationType::Province, 'vendor_id' => $bri->id, 'code' => 'P1', 'name' => 'P']);
        $sp = Fx::location(LocationType::ServicePoint, $province, vendor: $bri);
        $area = app(CreateLocation::class)->handle(['type' => LocationType::Area, 'parent_id' => $sp->id, 'vendor_id' => $cimb->id, 'code' => 'A1', 'name' => 'A']);

        expect($province->fresh()->vendor_id)->toBeNull()->and($area->fresh()->vendor_id)->toBe($bri->id);
    });

    it('refuses an unknown vendor on a service point', function () {
        $province = Fx::location(LocationType::Province);

        expect(fn () => app(CreateLocation::class)->handle(['type' => LocationType::ServicePoint, 'parent_id' => $province->id, 'vendor_id' => 99999, 'code' => 'S1', 'name' => 'S']))->toThrow(ValidationException::class);
    });

    it('keeps service point codes unique per vendor, not across vendors', function () {
        $bri = Fx::vendor('BRI');
        $cimb = Fx::vendor('CIMB');
        $province = Fx::location(LocationType::Province);

        Fx::location(LocationType::ServicePoint, $province, 'SP01', $bri);
        Fx::location(LocationType::ServicePoint, $province, 'SP01', $cimb);

        expect(Location::query()->where('code', 'SP01')->count())->toBe(2);
        expect(fn () => Fx::location(LocationType::ServicePoint, $province, 'sp01', $bri))->toThrow(ValidationException::class);
    });

    it('moves the areas to a new vendor with their service point', function () {
        $bri = Fx::vendor('BRI');
        $cimb = Fx::vendor('CIMB');
        ['service_point' => $sp, 'area' => $area] = Fx::chain($bri);

        app(UpdateLocation::class)->handle($sp, ['vendor_id' => $cimb->id]);

        expect($sp->fresh()->vendor_id)->toBe($cimb->id)->and($area->fresh()->vendor_id)->toBe($cimb->id);
    });

    it('refuses to change the vendor while another vendor has merchants inside', function () {
        $bri = Fx::vendor('BRI');
        $cimb = Fx::vendor('CIMB');
        ['service_point' => $sp, 'area' => $area] = Fx::chain($bri);
        Fx::site(Fx::merchant($bri), $area);

        expect(fn () => app(UpdateLocation::class)->handle($sp, ['vendor_id' => $cimb->id]))->toThrow(ValidationException::class, 'vendor lain');
        expect($sp->fresh()->vendor_id)->toBe($bri->id);
    });

    it('keeps a merchant of one vendor out of another vendor\'s area', function () {
        $bri = Fx::vendor('BRI');
        $cimb = Fx::vendor('CIMB');
        ['area' => $area] = Fx::chain($bri);

        expect(fn () => Fx::site(Fx::merchant($cimb), $area))->toThrow(ValidationException::class, 'vendor lain');
        expect(Fx::site(Fx::merchant($bri), $area)->exists)->toBeTrue();
    });

    it('lets a site attach only to a managed area', function () {
        ['service_point' => $sp] = Fx::chain();

        expect(fn () => Fx::site(Fx::merchant(Fx::vendor()), $sp))->toThrow(ValidationException::class, 'Area Kelolaan');
    });
});
