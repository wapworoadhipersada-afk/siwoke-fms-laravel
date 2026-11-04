<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Asset\Database\Seeders\AssetCatalogSeeder;
use Modules\Asset\Domain\Models\AssetMovementItem;
use Modules\Asset\Tests\Support\AssetFixtures as Fx;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Master\Tests\Support\MasterFixtures;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, AssetCatalogSeeder::class]);
    $this->vendor = MasterFixtures::vendor();
    $this->warehouse = Fx::warehouse();
    [$this->asset] = Fx::receive(Fx::edcModel(), $this->vendor, $this->warehouse, ['SN-1']);
});

describe('the ledger is append-only', function () {
    it('refuses to update a movement', function () {
        DB::table('asset_movements')->update(['notes' => 'rewritten history']);
    })->throws(QueryException::class, 'append-only');

    it('refuses to delete a movement', function () {
        DB::table('asset_movements')->delete();
    })->throws(QueryException::class, 'append-only');

    it('refuses to update a movement item', function () {
        DB::table('asset_movement_items')->update(['quantity' => 1]);
    })->throws(QueryException::class, 'append-only');

    it('refuses to delete a movement item', function () {
        AssetMovementItem::query()->delete();
    })->throws(QueryException::class, 'append-only');

    it('refuses to truncate the ledger', function () {
        DB::statement('TRUNCATE asset_movement_items');
    })->throws(QueryException::class, 'append-only');
});

describe('the snapshot cannot contradict itself', function () {
    it('refuses an installed asset that is not at a terminal', function () {
        DB::table('assets')->where('id', $this->asset->id)->update(['status' => 'installed']);
    })->throws(QueryException::class);

    it('refuses an in-stock asset held by a technician', function () {
        DB::table('assets')->where('id', $this->asset->id)->update(['holder_type' => 'user', 'holder_id' => 1]);
    })->throws(QueryException::class);

    it('refuses an in-transit asset without a pending handover', function () {
        DB::table('assets')->where('id', $this->asset->id)->update(['status' => 'in_transit']);
    })->throws(QueryException::class);

    it('refuses an external holder that carries an id', function () {
        DB::table('assets')->where('id', $this->asset->id)->update(['status' => 'lost', 'holder_type' => 'external', 'holder_id' => 5]);
    })->throws(QueryException::class);

    it('refuses an asset with no owner vendor', function () {
        DB::table('assets')->where('id', $this->asset->id)->update(['owner_vendor_id' => null]);
    })->throws(QueryException::class);

    it('refuses an unknown status', function () {
        DB::table('assets')->where('id', $this->asset->id)->update(['status' => 'vanished']);
    })->throws(QueryException::class);

    it('refuses a duplicate serial number for the same model, case-insensitively', function () {
        DB::table('assets')->insert([
            'ulid' => (string) Str::ulid(), 'asset_model_id' => $this->asset->asset_model_id, 'owner_vendor_id' => $this->vendor->id,
            'serial_number' => 'sn-1', 'holder_type' => 'warehouse', 'holder_id' => $this->warehouse->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    })->throws(QueryException::class);

    it('allows the same serial number under a different model', function () {
        Fx::receive(Fx::edcModel('PAX', 'A920'), $this->vendor, $this->warehouse, ['SN-1']);

        expect(DB::table('assets')->whereRaw("lower(serial_number) = 'sn-1'")->count())->toBe(2);
    });

    it('refuses a negative stock balance', function () {
        $paper = Fx::paperModel();
        Fx::receiveStock($paper, $this->vendor, $this->warehouse, 5);

        DB::table('asset_stocks')->update(['quantity' => -1]);
    })->throws(QueryException::class);
});
