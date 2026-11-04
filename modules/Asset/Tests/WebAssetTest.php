<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Asset\Application\Actions\AcceptHandover;
use Modules\Asset\Application\Actions\MoveAssets;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Database\Seeders\AssetCatalogSeeder;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Enums\HandoverStatus;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetCategory;
use Modules\Asset\Domain\Models\AssetHandover;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Asset\Domain\Models\AssetStock;
use Modules\Asset\Domain\Models\Warehouse;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\Asset\Tests\Support\AssetFixtures as Fx;
use Modules\IAM\Database\Seeders\RolesAndPermissionsSeeder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Application\Actions\SyncUserLocationScopes;
use Modules\Master\Tests\Support\MasterFixtures;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, AssetCatalogSeeder::class]);
    $this->vendor = MasterFixtures::vendor('BRI');
    $this->admin = User::factory()->create()->assignRole(RoleName::OperationalAdmin->value)->givePermissionTo(Permissions::DATA_SCOPE_ALL);
    $this->super = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);
});

describe('access control', function () {
    it('sends guests to the login page', function (string $uri) {
        $this->get($uri)->assertRedirect(route('login'));
    })->with(['/asset/assets', '/asset/models', '/asset/warehouses', '/asset/stock', '/asset/handovers']);

    it('keeps catalog and receiving pages away from a technician', function (string $uri) {
        $this->actingAs(Fx::technician())->get($uri)->assertForbidden();
    })->with(['/asset/models/create', '/asset/warehouses/create', '/asset/assets/create', '/asset/handovers/create']);

    it('lets a technician read the asset list, which shows only what they hold', function () {
        $warehouse = Fx::warehouse();
        $tech = Fx::technician();
        [$mine, $other] = Fx::receive(Fx::edcModel(), $this->vendor, $warehouse, ['MINE', 'OTHER']);
        Fx::issue($warehouse, $tech, [$mine]);

        $this->actingAs($tech)->get('/asset/assets')
            ->assertInertia(fn (Assert $page) => $page->component('asset/assets/Index')->has('assets.data', 1)->where('assets.data.0.serial_number', 'MINE')->where('can.receive', false));
    });
});

describe('catalog', function () {
    it('creates, edits and deletes an asset model', function () {
        $category = AssetCategory::query()->where('code', 'EDC')->firstOrFail();

        $this->actingAs($this->admin)->post('/asset/models', ['category' => $category->ulid, 'brand' => 'Verifone', 'model_name' => 'VX520', 'is_active' => 1])
            ->assertRedirect('/asset/models');

        $model = AssetModel::query()->sole();

        $this->actingAs($this->admin)->put("/asset/models/{$model->ulid}", ['brand' => 'Verifone', 'model_name' => 'VX520 Plus', 'is_active' => 1])->assertRedirect('/asset/models');
        expect($model->fresh()->model_name)->toBe('VX520 Plus');

        $this->actingAs($this->admin)->delete("/asset/models/{$model->ulid}")->assertRedirect('/asset/models');
        expect(AssetModel::query()->count())->toBe(0);
    });

    it('does not accept a category change on update', function () {
        $model = Fx::edcModel();

        $this->actingAs($this->admin)->put("/asset/models/{$model->ulid}", ['category' => AssetCategory::query()->first()->ulid, 'brand' => 'X', 'model_name' => 'Y', 'is_active' => 1])
            ->assertSessionHasErrors('category');
    });

    it('lists models and filters by category', function () {
        Fx::edcModel();
        Fx::paperModel();
        $edc = AssetCategory::query()->where('code', 'EDC')->firstOrFail();

        $this->actingAs($this->admin)->get("/asset/models?category={$edc->ulid}")
            ->assertInertia(fn (Assert $page) => $page->component('asset/models/Index')->has('models.data', 1)->where('can.manage', true));
    });

    it('creates a warehouse in a location and refuses one outside the user area', function () {
        $west = MasterFixtures::chain();
        $east = MasterFixtures::chain();
        $regional = User::factory()->create()->assignRole(RoleName::OperationalAdmin->value);
        app(SyncUserLocationScopes::class)->handle($regional, [$west['province']->id]);

        $this->actingAs($regional)->post('/asset/warehouses', ['location' => $west['service_point']->ulid, 'code' => 'gdg-w', 'name' => 'Gudang Barat', 'is_active' => 1])
            ->assertRedirect('/asset/warehouses');

        $this->actingAs($regional)->post('/asset/warehouses', ['location' => $east['service_point']->ulid, 'code' => 'gdg-e', 'name' => 'Gudang Timur', 'is_active' => 1])
            ->assertForbidden();

        expect(Warehouse::query()->count())->toBe(1);
    });

    it('reports a duplicate warehouse code on the field', function () {
        $location = MasterFixtures::chain()['service_point'];
        Fx::warehouse($location);
        $existing = Warehouse::query()->sole();

        $this->actingAs($this->admin)->post('/asset/warehouses', ['location' => $location->ulid, 'code' => $existing->code, 'name' => 'Dup', 'is_active' => 1])
            ->assertSessionHasErrors('code');
    });
});

describe('receiving', function () {
    beforeEach(function () {
        $this->warehouse = Fx::warehouse();
        $this->edc = Fx::edcModel();
    });

    it('registers units from a list of serials, one per line', function () {
        $this->actingAs($this->admin)->post('/asset/assets', [
            'asset_model' => $this->edc->ulid, 'vendor' => $this->vendor->ulid, 'warehouse' => $this->warehouse->ulid,
            'serials' => "SN-1\nSN-2, SN-3\r\n\n SN-4 ", 'notes' => 'Kiriman 1',
        ])->assertRedirect('/asset/assets');

        expect(Asset::query()->orderBy('id')->pluck('serial_number')->all())->toBe(['SN-1', 'SN-2', 'SN-3', 'SN-4']);
    });

    it('adds stock for a consumable', function () {
        $paper = Fx::paperModel();

        $this->actingAs($this->admin)->post('/asset/assets', [
            'asset_model' => $paper->ulid, 'vendor' => $this->vendor->ulid, 'warehouse' => $this->warehouse->ulid, 'quantity' => 40,
        ])->assertRedirect('/asset/assets');

        expect(AssetStock::query()->sole()->quantity)->toBe(40);
    });

    it('reports a duplicate serial on the serials field', function () {
        Fx::receive($this->edc, $this->vendor, $this->warehouse, ['SN-1']);

        $this->actingAs($this->admin)->post('/asset/assets', [
            'asset_model' => $this->edc->ulid, 'vendor' => $this->vendor->ulid, 'warehouse' => $this->warehouse->ulid, 'serials' => 'sn-1',
        ])->assertSessionHasErrors('serials');
    });

    it('rejects an unknown model', function () {
        $this->actingAs($this->admin)->post('/asset/assets', [
            'asset_model' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'vendor' => $this->vendor->ulid, 'warehouse' => $this->warehouse->ulid, 'serials' => 'X',
        ])->assertSessionHasErrors('asset_model');
    });

    it('will not receive into a warehouse outside the user area', function () {
        $regional = User::factory()->create()->assignRole(RoleName::OperationalAdmin->value);
        app(SyncUserLocationScopes::class)->handle($regional, [MasterFixtures::chain()['province']->id]);

        $this->actingAs($regional)->post('/asset/assets', [
            'asset_model' => $this->edc->ulid, 'vendor' => $this->vendor->ulid, 'warehouse' => $this->warehouse->ulid, 'serials' => 'X',
        ])->assertSessionHasErrors('warehouse');

        expect(Asset::query()->count())->toBe(0);
    });
});

describe('looking at an asset', function () {
    beforeEach(function () {
        $this->warehouse = Fx::warehouse();
        $this->tech = Fx::technician();
        [$this->asset] = Fx::receive(Fx::edcModel(), $this->vendor, $this->warehouse, ['SN-1']);
        Fx::issue($this->warehouse, $this->tech, [$this->asset]);
    });

    it('shows the current holder and the full history, newest first', function () {
        $this->actingAs($this->admin)->get("/asset/assets/{$this->asset->ulid}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('asset/assets/Show')
                ->where('asset.holder', $this->tech->name)
                ->where('asset.status', 'with_technician')
                ->has('history', 2)
                ->where('history.0.type_label', 'Serah ke teknisi')
                ->where('history.1.type_label', 'Penerimaan')
                ->where('history.0.to', $this->tech->name));
    });

    it('filters the list by status and searches by serial', function () {
        Fx::receive(Fx::edcModel(), $this->vendor, $this->warehouse, ['OTHER-9']);

        $this->actingAs($this->admin)->get('/asset/assets?status=in_stock&q=other')
            ->assertInertia(fn (Assert $page) => $page->has('assets.data', 1)->where('assets.data.0.serial_number', 'OTHER-9'));
    });

    it('answers 404 for an asset outside the user area', function () {
        $regional = User::factory()->create()->assignRole(RoleName::OperationalAdmin->value);
        app(SyncUserLocationScopes::class)->handle($regional, [MasterFixtures::chain()['province']->id]);

        $this->actingAs($regional)->get("/asset/assets/{$this->asset->ulid}")->assertNotFound();
    });

    it('updates condition and tag', function () {
        $this->actingAs($this->admin)->put("/asset/assets/{$this->asset->ulid}", ['condition' => 'damaged', 'asset_tag' => 'TAG-1'])->assertRedirect();

        expect($this->asset->fresh()->condition->value)->toBe('damaged')->and($this->asset->fresh()->asset_tag)->toBe('TAG-1');
    });

    it('refuses an unknown condition', function () {
        $this->actingAs($this->admin)->put("/asset/assets/{$this->asset->ulid}", ['condition' => 'haunted'])->assertSessionHasErrors('condition');
    });
});

describe('repair, loss and retirement', function () {
    beforeEach(function () {
        $this->warehouse = Fx::warehouse();
        [$this->asset] = Fx::receive(Fx::edcModel(), $this->vendor, $this->warehouse, ['SN-1']);
    });

    it('sends to repair and back', function () {
        $this->actingAs($this->admin)->post("/asset/assets/{$this->asset->ulid}/move", ['type' => 'send_to_repair'])->assertRedirect();
        expect($this->asset->fresh()->status)->toBe(AssetStatus::InRepair);

        $this->actingAs($this->admin)->post("/asset/assets/{$this->asset->ulid}/move", ['type' => 'return_from_repair', 'warehouse' => $this->warehouse->ulid])->assertRedirect();
        expect($this->asset->fresh()->status)->toBe(AssetStatus::InStock);
    });

    it('asks which warehouse an asset returns to', function () {
        $this->actingAs($this->admin)->post("/asset/assets/{$this->asset->ulid}/move", ['type' => 'send_to_repair']);

        $this->actingAs($this->admin)->post("/asset/assets/{$this->asset->ulid}/move", ['type' => 'return_from_repair'])->assertSessionHasErrors('warehouse');
    });

    it('requires a reason to write an asset off', function () {
        $this->actingAs($this->super)->post("/asset/assets/{$this->asset->ulid}/move", ['type' => 'mark_lost'])->assertSessionHasErrors('notes');

        $this->actingAs($this->super)->post("/asset/assets/{$this->asset->ulid}/move", ['type' => 'mark_lost', 'notes' => 'Hilang saat pengiriman'])->assertRedirect();
        expect($this->asset->fresh()->status)->toBe(AssetStatus::Lost);
    });

    it('keeps loss and retirement for approvers', function () {
        $this->actingAs($this->admin)->post("/asset/assets/{$this->asset->ulid}/move", ['type' => 'retire', 'notes' => 'Usang'])->assertForbidden();

        expect($this->asset->fresh()->status)->toBe(AssetStatus::InStock);
    });

    it('does not offer installing or custody changes through this endpoint', function (string $type) {
        $this->actingAs($this->super)->post("/asset/assets/{$this->asset->ulid}/move", ['type' => $type])->assertSessionHasErrors('type');
    })->with(['install', 'issue_to_technician', 'receive']);

    it('shows a rule failure on the action field', function () {
        $this->actingAs($this->admin)->post("/asset/assets/{$this->asset->ulid}/move", ['type' => 'return_from_repair', 'warehouse' => $this->warehouse->ulid])
            ->assertSessionHasErrors('type');
    });
});

describe('handing assets to a technician', function () {
    beforeEach(function () {
        $this->warehouse = Fx::warehouse();
        $this->tech = Fx::technician();
        [$this->a, $this->b] = Fx::receive(Fx::edcModel(), $this->vendor, $this->warehouse, ['SN-A', 'SN-B']);
        $this->paper = Fx::paperModel();
        Fx::receiveStock($this->paper, $this->vendor, $this->warehouse, 30);
    });

    it('lists what the chosen warehouse can give out', function () {
        $this->actingAs($this->admin)->get("/asset/handovers/create?warehouse={$this->warehouse->ulid}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('asset/handovers/Create')
                ->has('assets', 2)
                ->has('stocks', 1)
                ->where('stocks.0.available', 30)
                ->has('technicians', 1)
                ->where('selected', $this->warehouse->ulid));
    });

    it('offers units and a quantity in one request and waits for the technician', function () {
        $this->actingAs($this->admin)->post('/asset/handovers', [
            'warehouse' => $this->warehouse->ulid,
            'technician' => $this->tech->ulid,
            'assets' => [$this->a->ulid],
            'stock' => [['model' => $this->paper->ulid, 'vendor' => $this->vendor->ulid, 'quantity' => 10], ['model' => $this->paper->ulid, 'vendor' => $this->vendor->ulid, 'quantity' => 0]],
            'note' => 'Untuk tugas besok',
        ])->assertRedirect('/asset/handovers');

        $handover = AssetHandover::query()->with('items')->sole();

        expect($handover->status)->toBe(HandoverStatus::Pending)
            ->and($handover->items)->toHaveCount(2)
            ->and($this->a->fresh()->status)->toBe(AssetStatus::InTransit);
    });

    it('reports too much stock on the stock field', function () {
        $this->actingAs($this->admin)->post('/asset/handovers', [
            'warehouse' => $this->warehouse->ulid, 'technician' => $this->tech->ulid,
            'stock' => [['model' => $this->paper->ulid, 'vendor' => $this->vendor->ulid, 'quantity' => 31]],
        ])->assertSessionHasErrors('stock');
    });

    it('reports an empty request on the assets field', function () {
        $this->actingAs($this->admin)->post('/asset/handovers', ['warehouse' => $this->warehouse->ulid, 'technician' => $this->tech->ulid])
            ->assertSessionHasErrors('assets');
    });

    it('refuses a recipient who is not a technician', function () {
        $office = User::factory()->create()->assignRole(RoleName::Coordinator->value);

        $this->actingAs($this->admin)->post('/asset/handovers', ['warehouse' => $this->warehouse->ulid, 'technician' => $office->ulid, 'assets' => [$this->a->ulid]])
            ->assertSessionHasErrors('technician');
    });

    it('tells each person what they may do with a pending handover', function () {
        $handover = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)], $this->admin);

        $this->actingAs($this->tech)->get('/asset/handovers')
            ->assertInertia(fn (Assert $page) => $page->has('handovers.data', 1)->where('handovers.data.0.can_resolve', true)->where('handovers.data.0.can_cancel', false));

        $this->actingAs($this->admin)->get('/asset/handovers')
            ->assertInertia(fn (Assert $page) => $page->where('handovers.data.0.can_resolve', false)->where('handovers.data.0.can_cancel', true)->where('handovers.data.0.items.0', fn ($item) => str_contains($item, 'SN-A')));

        expect($handover->status)->toBe(HandoverStatus::Pending);
    });

    it('lets the technician accept it from the web, and nobody else', function () {
        $handover = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)], $this->admin);

        $this->actingAs($this->admin)->post("/asset/handovers/{$handover->ulid}/accept")->assertForbidden();
        // A technician with no part in it cannot even see it.
        $this->actingAs(Fx::technician())->post("/asset/handovers/{$handover->ulid}/accept")->assertNotFound();

        $this->actingAs($this->tech)->post("/asset/handovers/{$handover->ulid}/accept")->assertRedirect();

        expect($this->a->fresh()->holder_id)->toBe($this->tech->id);
    });

    it('lets the sender withdraw it but not a stranger', function () {
        $handover = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)], $this->admin);

        $this->actingAs(Fx::technician())->post("/asset/handovers/{$handover->ulid}/cancel")->assertNotFound();
        $this->actingAs($this->admin)->post("/asset/handovers/{$handover->ulid}/cancel")->assertRedirect();

        expect($this->a->fresh()->status)->toBe(AssetStatus::InStock);
    });

    it('filters the list by status', function () {
        $done = Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->a)], $this->admin);
        app(AcceptHandover::class)->handle($done, $this->tech);
        Fx::offer($this->warehouse, $this->tech, [MoveItem::asset($this->b)], $this->admin);

        $this->actingAs($this->admin)->get('/asset/handovers?status=accepted')
            ->assertInertia(fn (Assert $page) => $page->has('handovers.data', 1)->where('handovers.data.0.status', 'accepted'));
    });
});

describe('stock', function () {
    it('lists balances with the holder name and filters by vendor', function () {
        $warehouse = Fx::warehouse();
        $paper = Fx::paperModel();
        Fx::receiveStock($paper, $this->vendor, $warehouse, 25);
        Fx::receiveStock($paper, MasterFixtures::vendor('CIMB'), $warehouse, 5);

        $this->actingAs($this->admin)->get("/asset/stock?vendor={$this->vendor->ulid}")
            ->assertInertia(fn (Assert $page) => $page->component('asset/stock/Index')->has('stocks.data', 1)->where('stocks.data.0.quantity', 25)->where('stocks.data.0.holder', "Gudang {$warehouse->name}"));
    });

    it('hides balances outside the user area', function () {
        $paper = Fx::paperModel();
        Fx::receiveStock($paper, $this->vendor, Fx::warehouse(), 25);
        $regional = User::factory()->create()->assignRole(RoleName::OperationalAdmin->value);
        app(SyncUserLocationScopes::class)->handle($regional, [MasterFixtures::chain()['province']->id]);

        $this->actingAs($regional)->get('/asset/stock')->assertInertia(fn (Assert $page) => $page->has('stocks.data', 0));
    });
});

it('lets a super admin install an asset that a technician holds, through the engine', function () {
    $warehouse = Fx::warehouse();
    $tech = Fx::technician();
    $terminal = MasterFixtures::terminalIn($this->vendor, MasterFixtures::chain()['service_point']);
    [$asset] = Fx::receive(Fx::edcModel(), $this->vendor, $warehouse, ['SN-1']);
    Fx::issue($warehouse, $tech, [$asset]);

    app(MoveAssets::class)->handle(new MovementCommand(MovementType::Install, [MoveItem::asset($asset->fresh())], Holder::user($tech), Holder::terminal($terminal), $tech));

    $this->actingAs($this->super)->get("/asset/assets/{$asset->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('asset.status', 'installed')->where('asset.holder', fn ($holder) => str_contains($holder, $terminal->tid)));
});
