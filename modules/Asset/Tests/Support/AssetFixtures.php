<?php

declare(strict_types=1);

namespace Modules\Asset\Tests\Support;

use App\Models\User;
use Modules\Asset\Application\Actions\AcceptHandover;
use Modules\Asset\Application\Actions\CreateAssetModel;
use Modules\Asset\Application\Actions\CreateWarehouse;
use Modules\Asset\Application\Actions\ReceiveAssets;
use Modules\Asset\Application\Actions\RequestHandover;
use Modules\Asset\Application\DTOs\MoveItem;
use Modules\Asset\Application\DTOs\MovementCommand;
use Modules\Asset\Domain\Enums\MovementType;
use Modules\Asset\Domain\Models\Asset;
use Modules\Asset\Domain\Models\AssetCategory;
use Modules\Asset\Domain\Models\AssetHandover;
use Modules\Asset\Domain\Models\AssetModel;
use Modules\Asset\Domain\Models\AssetMovement;
use Modules\Asset\Domain\Models\Warehouse;
use Modules\Asset\Domain\ValueObjects\Holder;
use Modules\IAM\Domain\Enums\RoleName;
use Modules\IAM\Domain\Enums\UserType;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Application\Actions\SyncUserLocationScopes;
use Modules\Master\Domain\Models\Location;
use Modules\Master\Domain\Models\Vendor;
use Modules\Master\Tests\Support\MasterFixtures;

/**
 * Builds valid asset data through the real actions. Needs the roles and asset catalog seeders to have run.
 */
final class AssetFixtures
{
    private static int $sequence = 0;

    private static function next(): int
    {
        return ++self::$sequence;
    }

    public static function edcModel(string $brand = 'Verifone', string $name = 'VX520'): AssetModel
    {
        return app(CreateAssetModel::class)->handle([
            'asset_category_id' => AssetCategory::query()->where('code', 'EDC')->firstOrFail()->id,
            'brand' => $brand,
            'model_name' => $name.'-'.self::next(),
        ]);
    }

    public static function paperModel(): AssetModel
    {
        return app(CreateAssetModel::class)->handle([
            'asset_category_id' => AssetCategory::query()->where('code', 'THERMAL_PAPER')->firstOrFail()->id,
            'brand' => 'Generic',
            'model_name' => 'Roll 57mm-'.self::next(),
        ]);
    }

    public static function warehouse(?Location $location = null): Warehouse
    {
        $location ??= MasterFixtures::chain()['service_point'];

        return app(CreateWarehouse::class)->handle([
            'location_id' => $location->id,
            'code' => 'WH-'.self::next(),
            'name' => 'Gudang '.self::next(),
        ]);
    }

    public static function technician(): User
    {
        return User::factory()->create(['user_type' => UserType::Technician])->assignRole(RoleName::Technician->value);
    }

    /**
     * A leader who sees every area and may approve transfers (and so confirm a handover into a warehouse).
     */
    public static function approver(): User
    {
        return User::factory()->create()->assignRole(RoleName::Leader->value)->givePermissionTo(Permissions::DATA_SCOPE_ALL);
    }

    /**
     * A leader limited to the given locations, for tests about what restricted users can see and do.
     *
     * @param  list<Location>  $locations
     */
    public static function scopedApprover(array $locations): User
    {
        $leader = User::factory()->create()->assignRole(RoleName::Leader->value);

        app(SyncUserLocationScopes::class)->handle($leader, array_map(static fn (Location $location): int => $location->id, $locations));

        return $leader;
    }

    /**
     * @param  list<string>|null  $serials
     * @return list<Asset>
     */
    public static function receive(AssetModel $model, Vendor $vendor, Warehouse $warehouse, ?array $serials = null, ?User $actor = null): array
    {
        $serials ??= ['SN-'.self::next()];

        app(ReceiveAssets::class)->handle($model, $vendor, $warehouse, $actor ?? self::approver(), $serials);

        return Asset::query()->where('asset_model_id', $model->id)->whereIn('serial_number', $serials)->orderBy('id')->get()->all();
    }

    public static function receiveStock(AssetModel $model, Vendor $vendor, Warehouse $warehouse, int $quantity, ?User $actor = null): AssetMovement
    {
        return app(ReceiveAssets::class)->handle($model, $vendor, $warehouse, $actor ?? self::approver(), [], $quantity);
    }

    /**
     * Offers assets (or a quantity) from a warehouse to a technician and has the technician accept.
     *
     * @param  list<Asset>  $assets
     */
    public static function issue(Warehouse $warehouse, User $technician, array $assets, ?User $requester = null): AssetMovement
    {
        $handover = self::offer($warehouse, $technician, array_map(static fn (Asset $asset): MoveItem => MoveItem::asset($asset), $assets), $requester);

        return app(AcceptHandover::class)->handle($handover, $technician);
    }

    /**
     * @param  list<MoveItem>  $items
     */
    public static function offer(Warehouse $warehouse, User $technician, array $items, ?User $requester = null): AssetHandover
    {
        return app(RequestHandover::class)->handle(new MovementCommand(
            type: MovementType::IssueToTechnician,
            items: $items,
            from: Holder::warehouse($warehouse),
            to: Holder::user($technician),
            actor: $requester ?? self::approver(),
        ));
    }
}
