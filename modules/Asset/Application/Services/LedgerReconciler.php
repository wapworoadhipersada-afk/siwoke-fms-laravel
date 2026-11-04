<?php

declare(strict_types=1);

namespace Modules\Asset\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Asset\Domain\Enums\AssetStatus;
use Modules\Asset\Domain\Enums\MovementType;

/**
 * Replays the ledger and compares the result with the snapshots (asset holders and stock balances).
 * They are written in the same transaction and must always agree; any difference means a bug,
 * a manual database edit, or data loss, and is worth waking someone up for.
 */
final class LedgerReconciler
{
    /**
     * @return list<string> one human-readable line per discrepancy; empty when everything agrees
     */
    public function discrepancies(): array
    {
        return [...$this->assetDiscrepancies(), ...$this->stockDiscrepancies()];
    }

    /**
     * @return list<string>
     */
    private function assetDiscrepancies(): array
    {
        $rows = DB::select('
            SELECT a.id, a.serial_number, a.status, a.holder_type, a.holder_id, a.last_movement_id,
                   last.id AS movement_id, last.type AS movement_type, last.to_holder_type, last.to_holder_id
            FROM assets a
            LEFT JOIN LATERAL (
                SELECT m.id, m.type, m.to_holder_type, m.to_holder_id
                FROM asset_movement_items i
                JOIN asset_movements m ON m.id = i.movement_id
                WHERE i.asset_id = a.id
                ORDER BY m.id DESC
                LIMIT 1
            ) last ON true
            WHERE a.deleted_at IS NULL
        ');

        $problems = [];

        foreach ($rows as $row) {
            $label = "Aset #{$row->id} ({$row->serial_number})";

            if ($row->movement_id === null) {
                $problems[] = "{$label} tidak memiliki riwayat perpindahan sama sekali.";

                continue;
            }

            if ((int) $row->last_movement_id !== (int) $row->movement_id) {
                $problems[] = "{$label}: penunjuk perpindahan terakhir salah (snapshot {$row->last_movement_id}, ledger {$row->movement_id}).";
            }

            if ($row->holder_type !== $row->to_holder_type || (int) $row->holder_id !== (int) $row->to_holder_id) {
                $problems[] = "{$label}: pemegang di snapshot ({$row->holder_type}:{$row->holder_id}) berbeda dari ledger ({$row->to_holder_type}:{$row->to_holder_id}).";
            }

            // An asset waiting for a handover legitimately differs from its last completed movement in status only.
            if ($row->status !== AssetStatus::InTransit->value) {
                $expected = MovementType::from($row->movement_type)->resultStatus();

                if ($expected?->value !== $row->status) {
                    $problems[] = "{$label}: status di snapshot ({$row->status}) berbeda dari ledger ({$expected?->value}).";
                }
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function stockDiscrepancies(): array
    {
        $flows = DB::select("
            SELECT asset_model_id, owner_vendor_id, holder_type, holder_id, SUM(quantity) AS quantity FROM (
                SELECT i.asset_model_id, i.owner_vendor_id, m.to_holder_type AS holder_type, m.to_holder_id AS holder_id, i.quantity
                FROM asset_movement_items i JOIN asset_movements m ON m.id = i.movement_id
                WHERE i.asset_id IS NULL AND m.type <> 'adjustment' AND m.to_holder_type IN ('warehouse', 'user')
                UNION ALL
                SELECT i.asset_model_id, i.owner_vendor_id, m.from_holder_type, m.from_holder_id, -i.quantity
                FROM asset_movement_items i JOIN asset_movements m ON m.id = i.movement_id
                WHERE i.asset_id IS NULL AND m.type <> 'adjustment' AND m.from_holder_type IN ('warehouse', 'user')
                UNION ALL
                SELECT i.asset_model_id, i.owner_vendor_id, m.to_holder_type, m.to_holder_id, i.quantity
                FROM asset_movement_items i JOIN asset_movements m ON m.id = i.movement_id
                WHERE i.asset_id IS NULL AND m.type = 'adjustment'
            ) flows
            GROUP BY asset_model_id, owner_vendor_id, holder_type, holder_id
        ");

        $expected = [];

        foreach ($flows as $row) {
            $expected["{$row->asset_model_id}:{$row->owner_vendor_id}:{$row->holder_type}:{$row->holder_id}"] = (int) $row->quantity;
        }

        $actual = [];

        foreach (DB::table('asset_stocks')->get() as $row) {
            $actual["{$row->asset_model_id}:{$row->owner_vendor_id}:{$row->holder_type}:{$row->holder_id}"] = (int) $row->quantity;
        }

        $problems = [];

        foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
            $replayed = $expected[$key] ?? 0;
            $stored = $actual[$key] ?? 0;

            if ($replayed !== $stored) {
                $problems[] = "Stok {$key}: saldo tersimpan {$stored}, hasil ledger {$replayed}.";
            }
        }

        return $problems;
    }
}
