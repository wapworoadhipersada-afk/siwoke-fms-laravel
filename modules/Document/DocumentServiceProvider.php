<?php

declare(strict_types=1);

namespace Modules\Document;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Document\Console\VerifyAttachmentsCommand;

final class DocumentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        Route::middleware('api')
            ->prefix('api/mobile/v1')
            ->name('api.mobile.v1.')
            ->group(__DIR__.'/routes/api_v1.php');

        // Uploads are the heaviest thing a phone can ask of us, so they get their own, tighter budget.
        RateLimiter::for('uploads', static fn (Request $request): Limit => Limit::perMinute(60)->by(
            ($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.$request->header('X-Device-Id', ''),
        ));

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAttachmentsCommand::class]);
        }
    }
}
