<?php

declare(strict_types=1);

namespace Modules\Ticket\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ticket\Domain\Models\TicketType;

/**
 * Sets which assets a finished visit must record, for the standard ticket types that have no rule yet.
 * Types an administrator has already configured are left alone. Kept apart from the catalog seeder so tests
 * that are not about assets can close tickets freely.
 */
class TicketAssetRulesSeeder extends Seeder
{
    public function run(): void
    {
        $standard = [
            'INSTALL' => ['finish' => ['install' => 1]],
            'PULLOUT' => ['finish' => ['uninstall' => 1]],
            'REPLACE' => ['finish' => ['install' => 1, 'uninstall' => 1]],
        ];

        foreach ($standard as $code => $rules) {
            $type = TicketType::query()->where('code', $code)->first();

            if ($type instanceof TicketType && $type->asset_rules === []) {
                $type->forceFill(['asset_rules' => $rules])->save();
            }
        }
    }
}
