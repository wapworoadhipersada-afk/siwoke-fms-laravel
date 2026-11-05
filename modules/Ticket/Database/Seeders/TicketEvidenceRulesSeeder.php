<?php

declare(strict_types=1);

namespace Modules\Ticket\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ticket\Domain\Models\TicketType;

/**
 * Sets the standard evidence requirements on ticket types that have none yet. Types an administrator
 * has already configured are left alone, so this is safe to run on every deploy.
 *
 * Kept apart from the type catalog so tests that are not about evidence can close tickets freely.
 */
class TicketEvidenceRulesSeeder extends Seeder
{
    public function run(): void
    {
        $standard = [
            'finish' => ['photo_before' => 1, 'photo_after' => 1, 'signature_merchant' => 1],
            'pending' => ['photo_evidence' => 1],
            'done_negative' => ['photo_evidence' => 1],
        ];

        TicketType::query()->get()->each(function (TicketType $type) use ($standard): void {
            if ($type->evidence_rules === []) {
                $type->forceFill(['evidence_rules' => $standard])->save();
            }
        });
    }
}
