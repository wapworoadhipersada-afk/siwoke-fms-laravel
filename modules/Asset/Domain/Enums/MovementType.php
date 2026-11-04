<?php

declare(strict_types=1);

namespace Modules\Asset\Domain\Enums;

/**
 * Every way an asset can change hands. The rules for each type (where it may start,
 * where it may end, what state the asset must be in, and whether the receiving side must
 * confirm) live here so the movement engine has no special cases of its own.
 */
enum MovementType: string
{
    case Receive = 'receive';
    case IssueToTechnician = 'issue_to_technician';
    case TransferBetweenTechnicians = 'transfer_between_technicians';
    case ReturnToWarehouse = 'return_to_warehouse';
    case TransferBetweenWarehouses = 'transfer_between_warehouses';
    case Install = 'install';
    case Uninstall = 'uninstall';
    case SendToRepair = 'send_to_repair';
    case ReturnFromRepair = 'return_from_repair';
    case MarkLost = 'mark_lost';
    case Retire = 'retire';
    case Consume = 'consume';
    case Adjustment = 'adjustment';

    /**
     * @return list<HolderType>
     */
    public function fromHolders(): array
    {
        return match ($this) {
            self::Receive, self::ReturnFromRepair => [HolderType::External],
            self::IssueToTechnician, self::TransferBetweenWarehouses, self::SendToRepair => [HolderType::Warehouse],
            self::TransferBetweenTechnicians, self::ReturnToWarehouse, self::Install => [HolderType::User],
            self::Uninstall => [HolderType::Terminal],
            self::MarkLost => [HolderType::Warehouse, HolderType::User, HolderType::Terminal, HolderType::External],
            self::Retire => [HolderType::Warehouse, HolderType::External],
            self::Consume, self::Adjustment => [HolderType::Warehouse, HolderType::User],
        };
    }

    /**
     * @return list<HolderType>
     */
    public function toHolders(): array
    {
        return match ($this) {
            self::Receive, self::ReturnToWarehouse, self::TransferBetweenWarehouses, self::ReturnFromRepair => [HolderType::Warehouse],
            self::IssueToTechnician, self::TransferBetweenTechnicians, self::Uninstall => [HolderType::User],
            self::Install => [HolderType::Terminal],
            self::SendToRepair, self::MarkLost, self::Retire, self::Consume => [HolderType::External],
            self::Adjustment => [HolderType::Warehouse, HolderType::User],
        };
    }

    /**
     * The status a serialized asset ends up in.
     */
    public function resultStatus(): ?AssetStatus
    {
        return match ($this) {
            self::Receive, self::ReturnToWarehouse, self::TransferBetweenWarehouses, self::ReturnFromRepair => AssetStatus::InStock,
            self::IssueToTechnician, self::TransferBetweenTechnicians, self::Uninstall => AssetStatus::WithTechnician,
            self::Install => AssetStatus::Installed,
            self::SendToRepair => AssetStatus::InRepair,
            self::MarkLost => AssetStatus::Lost,
            self::Retire => AssetStatus::Retired,
            self::Consume, self::Adjustment => null,
        };
    }

    /**
     * The statuses a serialized asset may be in when this movement starts.
     *
     * @return list<AssetStatus>
     */
    public function allowedFromStatuses(): array
    {
        return match ($this) {
            self::Receive, self::Consume, self::Adjustment => [],
            self::IssueToTechnician, self::TransferBetweenWarehouses, self::SendToRepair => [AssetStatus::InStock],
            self::TransferBetweenTechnicians, self::ReturnToWarehouse, self::Install => [AssetStatus::WithTechnician],
            self::Uninstall => [AssetStatus::Installed],
            self::ReturnFromRepair => [AssetStatus::InRepair],
            self::MarkLost => [AssetStatus::InStock, AssetStatus::WithTechnician, AssetStatus::Installed, AssetStatus::InRepair],
            self::Retire => [AssetStatus::InStock, AssetStatus::InRepair],
        };
    }

    /**
     * Custody changes between people or places: the receiving side confirms before it counts.
     */
    public function requiresHandover(): bool
    {
        return match ($this) {
            self::IssueToTechnician, self::TransferBetweenTechnicians, self::ReturnToWarehouse, self::TransferBetweenWarehouses => true,
            default => false,
        };
    }

    public function supports(TrackingMode $mode): bool
    {
        return match ($mode) {
            TrackingMode::Serialized => $this !== self::Consume && $this !== self::Adjustment,
            TrackingMode::Quantity => match ($this) {
                self::Receive, self::IssueToTechnician, self::TransferBetweenTechnicians, self::ReturnToWarehouse, self::TransferBetweenWarehouses, self::Consume, self::Adjustment => true,
                default => false,
            },
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Receive => 'Penerimaan',
            self::IssueToTechnician => 'Serah ke teknisi',
            self::TransferBetweenTechnicians => 'Pindah antar teknisi',
            self::ReturnToWarehouse => 'Pengembalian ke gudang',
            self::TransferBetweenWarehouses => 'Pindah antar gudang',
            self::Install => 'Dipasang di merchant',
            self::Uninstall => 'Ditarik dari merchant',
            self::SendToRepair => 'Kirim ke perbaikan',
            self::ReturnFromRepair => 'Kembali dari perbaikan',
            self::MarkLost => 'Dinyatakan hilang',
            self::Retire => 'Dipensiunkan',
            self::Consume => 'Dipakai habis',
            self::Adjustment => 'Penyesuaian stok',
        };
    }
}
