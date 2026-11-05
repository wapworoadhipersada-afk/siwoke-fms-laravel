<?php

declare(strict_types=1);

namespace Modules\Report\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Asset\Application\Services\LedgerReconciler;
use Modules\Integration\Domain\Models\OutboundEvent;
use Throwable;

/**
 * One command a monitoring tool can run every few minutes: it checks the things that quietly break a field
 * service (database, cache, file storage, stuck vendor callbacks, failed jobs, the asset ledger) and exits
 * with a failure code if any of them is wrong, listing what.
 */
final class HealthCheckCommand extends Command
{
    protected $signature = 'fms:health {--skip-ledger : Skip the asset ledger replay, which can be slow on a large ledger}';

    protected $description = 'Check database, cache, storage, stuck callbacks, failed jobs and the asset ledger';

    public function handle(LedgerReconciler $ledger): int
    {
        $problems = [];

        $this->check($problems, 'Database', fn () => DB::select('select 1'));
        $this->check($problems, 'Cache', function (): void {
            Cache::put('fms:health', 'ok', 10);

            if (Cache::get('fms:health') !== 'ok') {
                throw new \RuntimeException('nilai tidak kembali');
            }
        });
        $this->check($problems, 'Penyimpanan bukti', function (): void {
            $disk = Storage::disk((string) config('documents.disk'));
            $probe = '.health-'.uniqid();
            $disk->put($probe, 'ok');
            $disk->delete($probe);
        });

        $dead = OutboundEvent::query()->where('status', OutboundEvent::DEAD)->count();

        if ($dead > 0) {
            $problems[] = "{$dead} callback ke vendor gagal total dan menunggu ditangani.";
        }

        $stale = OutboundEvent::query()->where('status', OutboundEvent::PENDING)->where('next_attempt_at', '<', now()->subMinutes(15))->count();

        if ($stale > 0) {
            $problems[] = "{$stale} callback tertunda lebih dari 15 menit; periksa queue worker dan scheduler.";
        }

        $failed = DB::table('failed_jobs')->count();

        if ($failed > 0) {
            $problems[] = "{$failed} job gagal di antrean (php artisan queue:failed).";
        }

        if (! $this->option('skip-ledger')) {
            $differences = $ledger->discrepancies();

            if ($differences !== []) {
                $problems[] = count($differences).' ketidaksesuaian pada buku aset (php artisan asset:reconcile).';
            }
        }

        if ($problems === []) {
            $this->info('Semua pemeriksaan lolos.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return self::FAILURE;
    }

    /**
     * @param  list<string>  $problems
     */
    private function check(array &$problems, string $name, callable $probe): void
    {
        try {
            $probe();
        } catch (Throwable $exception) {
            $problems[] = "{$name} bermasalah: ".class_basename($exception).' — '.$exception->getMessage();
        }
    }
}
