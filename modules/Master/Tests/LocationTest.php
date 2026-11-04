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

it('builds the materialized path and depth through all four levels', function () {
    ['province' => $p, 'city' => $c, 'area' => $a, 'service_point' => $s] = Fx::chain();

    expect($p->fresh()->path)->toBe("/{$p->id}/")
        ->and($p->fresh()->depth)->toBe(1)
        ->and($c->fresh()->path)->toBe("/{$p->id}/{$c->id}/")
        ->and($a->fresh()->depth)->toBe(3)
        ->and($s->fresh()->path)->toBe("/{$p->id}/{$c->id}/{$a->id}/{$s->id}/")
        ->and($s->fresh()->depth)->toBe(4);
});

it('selects a whole subtree with one prefix match', function () {
    ['province' => $p, 'city' => $c, 'area' => $a, 'service_point' => $s] = Fx::chain();
    $other = Fx::chain();

    $ids = Location::query()->where('path', 'like', $c->fresh()->path.'%')->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing([$c->id, $a->id, $s->id])
        ->and($ids)->not->toContain($p->id, $other['province']->id);
});

it('knows whether it descends from another location', function () {
    ['province' => $p, 'service_point' => $s] = Fx::chain();

    expect($s->fresh()->isDescendantOf($p->fresh()))->toBeTrue()
        ->and($p->fresh()->isDescendantOf($s->fresh()))->toBeFalse()
        ->and($p->fresh()->isDescendantOf($p->fresh()))->toBeFalse();
});

it('rejects a child under the wrong parent type', function (LocationType $type, ?string $parentLevel, string $message) {
    $chain = Fx::chain();
    $parent = $parentLevel === null ? null : $chain[$parentLevel];

    app(CreateLocation::class)->handle([
        'type' => $type, 'parent_id' => $parent?->id, 'code' => 'X1', 'name' => 'X',
    ]);
})->with([
    'service point under a city' => [LocationType::ServicePoint, 'city', 'Service Point harus berada di bawah Area'],
    'area under a province' => [LocationType::Area, 'province', 'Area harus berada di bawah Kota/Kabupaten'],
    'city without a parent' => [LocationType::City, null, 'harus berada di bawah Provinsi'],
    'province with a parent' => [LocationType::Province, 'province', 'tidak boleh memiliki induk'],
])->throws(ValidationException::class);

it('enforces a province-only root at the database level', function () {
    DB::table('locations')->insert([
        'ulid' => (string) Str::ulid(), 'type' => 'city', 'code' => 'ORPHAN', 'name' => 'Orphan',
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
    Fx::location(LocationType::City, $province, '32');

    expect(Location::query()->where('code', '32')->count())->toBe(2);
});

it('rewrites the paths of the whole subtree when a node moves', function () {
    ['province' => $oldProvince, 'city' => $city, 'area' => $area, 'service_point' => $sp] = Fx::chain();
    $newProvince = Fx::location(LocationType::Province);

    app(UpdateLocation::class)->handle($city, ['parent_id' => $newProvince->id]);

    expect($city->fresh()->path)->toBe("/{$newProvince->id}/{$city->id}/")
        ->and($area->fresh()->path)->toBe("/{$newProvince->id}/{$city->id}/{$area->id}/")
        ->and($sp->fresh()->path)->toBe("/{$newProvince->id}/{$city->id}/{$area->id}/{$sp->id}/")
        ->and($sp->fresh()->isDescendantOf($newProvince->fresh()))->toBeTrue()
        ->and($sp->fresh()->isDescendantOf($oldProvince->fresh()))->toBeFalse();
});

it('refuses to move a node under a parent of the wrong type', function () {
    ['city' => $city, 'area' => $area] = Fx::chain();

    app(UpdateLocation::class)->handle($city, ['parent_id' => $area->id]);
})->throws(ValidationException::class);

it('updates plain attributes without touching the path', function () {
    ['city' => $city] = Fx::chain();
    $path = $city->fresh()->path;

    app(UpdateLocation::class)->handle($city, ['name' => 'Kota Bandung', 'is_active' => false]);

    expect($city->fresh()->name)->toBe('Kota Bandung')
        ->and($city->fresh()->is_active)->toBeFalse()
        ->and($city->fresh()->path)->toBe($path);
});

it('refuses to delete a location that still has children', function () {
    ['province' => $province] = Fx::chain();

    app(DeleteLocation::class)->handle($province);
})->throws(ValidationException::class);

it('refuses to delete a location used by a merchant site', function () {
    ['service_point' => $sp] = Fx::chain();
    Fx::site(Fx::merchant(Fx::vendor()), $sp);

    app(DeleteLocation::class)->handle($sp);
})->throws(ValidationException::class);

it('soft-deletes a leaf location and frees its code', function () {
    ['service_point' => $sp] = Fx::chain();
    $code = $sp->code;

    app(DeleteLocation::class)->handle($sp);

    expect(Location::query()->find($sp->id))->toBeNull()
        ->and(Location::withTrashed()->find($sp->id))->not->toBeNull();

    Fx::location(LocationType::ServicePoint, $sp->parent, $code);

    expect(Location::query()->where('code', $code)->count())->toBe(1);
});
