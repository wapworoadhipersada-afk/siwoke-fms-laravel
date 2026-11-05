<?php

declare(strict_types=1);

namespace Modules\Integration\Console;

use Illuminate\Console\Command;
use Modules\Integration\Application\Jobs\DeliverOutboundEvent;
use Modules\Integration\Domain\Models\OutboundEvent;

/**
 * Wakes up every callback that is due: first attempts a queue worker never picked up (after a crash or a lost
 * job), and retries whose waiting time has passed. Scheduled every minute.
 */
final class DeliverDueEventsCommand extends Command
{
    protected $signature = 'integration:deliver-due';

    protected $description = 'Dispatch outbound vendor callbacks that are due';

    public function handle(): int
    {
        $count = 0;

        OutboundEvent::query()
            ->where('status', OutboundEvent::PENDING)
            ->where('next_attempt_at', '<=', now())
            ->orderBy('id')
            ->limit(500)
            ->pluck('id')
            ->each(function (int $id) use (&$count): void {
                DeliverOutboundEvent::dispatch($id);
                $count++;
            });

        $this->info("{$count} callback dikirim ke antrean.");

        return self::SUCCESS;
    }
}
