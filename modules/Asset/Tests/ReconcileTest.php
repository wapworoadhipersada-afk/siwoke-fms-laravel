<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Asset\Application\Actions\AcceptHandover;
use Modules\Asset\Application\Actions\AdjustStock;
use Modules\Asset\Application\Actions\MoveAssets;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Application\Services\LedgerReconciler;
use Modules\Asset\Database\Seeders\AssetCatalogSeeder;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Asset\Tests\Support\AssetFixtures as Fx;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\Master\Tests\Support\MasterFixtures;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, AssetCatalogSeeder::class]);
    $vendor = MasterFixtures::vendor('BRI');
    $this->warehouse = Fx::warehouse();
    $this->tech = Fx::technician();
    $leader = Fx::approver();
    $terminal = MasterFixtures::terminalIn($vendor, MasterFixtures::chain()['area']);

    [$this->a, $this->b, $this->c] = Fx::receive(Fx::edcModel(), $vendor, $this->warehouse, ['SN-A', 'SN-B', 'SN-C'], $leader);
    Fx::issue($this->warehouse, $this->tech, [$this->a, $this->b], $leader);
    app(MoveAssets::class)->handle(new MovementCommand(
        MovementType::Install, [MoveItem::asset($this->a->fresh())], Holder::user($this->tech), Holder::terminal($terminal), $this->tech,
    ));
    // c is offered and still waiting, so it is in transit.
    $this->pending = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->c)], $leader);

    $paper = Fx::paperModel();
    $this->paper = $paper;
    $this->vendor = $vendor;
    Fx::receiveStock($paper, $vendor, $this->warehouse, 100, $leader);
    app(AcceptHandover::class)->handle(Fx::offer($this->warehouse, $this->tech, [MoveItem::stock($paper, $vendor, 30)], $leader), $this->tech);
    app(AdjustStock::class)->handle($paper, $vendor, Holder::warehouse($this->warehouse), -4, $leader, 'Opname');
});

it('finds the snapshots in agreement with the ledger after normal use', function () {
    expect(app(LedgerReconciler::class)->discrepancies())->toBe([]);
});

it('reports an asset whose holder was changed behind the ledger', function () {
    DB::table('assets')->where('id', $this->b->id)->update(['holder_type' => 'user', 'holder_id' => Fx::technician()->id]);

    $problems = app(LedgerReconciler::class)->discrepancies();

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('SN-B')->toContain('pemegang');
});

it('reports an asset whose status was changed behind the ledger', function () {
    DB::table('assets')->where('id', $this->b->id)->update(['holder_type' => 'external', 'holder_id' => null, 'status' => 'lost']);

    $problems = app(LedgerReconciler::class)->discrepancies();

    expect($problems)->not->toBe([])
        ->and(implode(' ', $problems))->toContain('SN-B');
});

it('reports an asset that has no history at all', function () {
    DB::table('assets')->insert([
        'ulid' => (string) Str::ulid(), 'asset_model_id' => $this->a->asset_model_id, 'owner_vendor_id' => $this->vendor->id,
        'serial_number' => 'GHOST', 'holder_type' => 'warehouse', 'holder_id' => $this->warehouse->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(LedgerReconciler::class)->discrepancies()[0])->toContain('GHOST')->toContain('tidak memiliki riwayat');
});

it('reports a stock balance edited behind the ledger', function () {
    DB::table('asset_stocks')->where('holder_type', 'warehouse')->update(['quantity' => 999]);

    $problems = app(LedgerReconciler::class)->discrepancies();

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('999')->toContain('66');
});

it('reports a stock balance that has no ledger behind it', function () {
    DB::table('asset_stocks')->insert([
        'asset_model_id' => $this->paper->id, 'owner_vendor_id' => $this->vendor->id, 'holder_type' => 'user', 'holder_id' => Fx::technician()->id,
        'quantity' => 8, 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(LedgerReconciler::class)->discrepancies())->toHaveCount(1);
});

describe('the artisan command', function () {
    it('succeeds when everything agrees', function () {
        $this->artisan('asset:reconcile')->expectsOutputToContain('sinkron')->assertSuccessful();
    });

    it('fails and lists the problem when something drifted', function () {
        DB::table('asset_stocks')->where('holder_type', 'warehouse')->update(['quantity' => 1]);

        $this->artisan('asset:reconcile')->expectsOutputToContain('ketidaksesuaian')->assertFailed();
    });
});
