<?php

declare(strict_types=1);

namespace Modules\IAM\Console;

use Illuminate\Console\Command;
use Modules\IAM\Application\Services\SyncNavigationCatalog;

final class SyncNavigationCommand extends Command
{
    protected $signature = 'iam:sync-navigation';

    protected $description = 'Add sidebar entries from the code catalog that are missing; never changes existing ones';

    public function handle(SyncNavigationCatalog $sync): int
    {
        $added = $sync->handle();
        $this->info($added === 0 ? 'Semua halaman sudah ada di menu.' : "{$added} halaman ditambahkan ke menu.");

        return self::SUCCESS;
    }
}
