<?php

declare(strict_types=1);

namespace Modules\Asset\Console;

use Illuminate\Console\Command;
use Modules\Asset\Application\Services\LedgerReconciler;

final class ReconcileAssetsCommand extends Command
{
    protected $signature = 'asset:reconcile';

    protected $description = 'Replay the asset ledger and report any difference from the stored holders and stock balances';

    public function handle(LedgerReconciler $reconciler): int
    {
        $problems = $reconciler->discrepancies();

        if ($problems === []) {
            $this->info('Ledger dan snapshot sinkron.');

            return self::SUCCESS;
        }

        $this->error(count($problems).' ketidaksesuaian ditemukan:');

        foreach ($problems as $problem) {
            $this->line(" - {$problem}");
        }

        return self::FAILURE;
    }
}
