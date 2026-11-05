<?php

declare(strict_types=1);

namespace Modules\Integration;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Integration\Application\Listeners\QueueOutboundEvents;
use Modules\Integration\Console\DeliverDueEventsCommand;
use Modules\Integration\Domain\Models\IntegrationClient;
use Modules\Integration\Http\Api\V1\Middleware\VerifyVendorRequest;
use Modules\Ticket\Domain\Events\TicketStatusRecorded;

final class IntegrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        // Strangers are slowed down by address before any work is done for them; known clients get a budget of their own.
        RateLimiter::for('vendor-auth', static fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip()));
        RateLimiter::for('vendor-api', static function (Request $request): Limit {
            $client = $request->attributes->get('integration.client');

            return Limit::perMinute((int) config('integration.requests_per_minute'))
                ->by($client instanceof IntegrationClient ? "client:{$client->id}" : 'ip:'.$request->ip());
        });

        Route::middleware(['api', 'throttle:vendor-auth', VerifyVendorRequest::class, 'throttle:vendor-api'])
            ->prefix('api/vendor/v1')
            ->name('api.vendor.v1.')
            ->group(__DIR__.'/routes/api_v1.php');

        // Inside the transaction of the status change, so the outbox row and the change stand or fall together.
        Event::listen(TicketStatusRecorded::class, QueueOutboundEvents::class);

        if ($this->app->runningInConsole()) {
            $this->commands([DeliverDueEventsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('integration:deliver-due')->everyMinute()->withoutOverlapping();
        });
    }
}
