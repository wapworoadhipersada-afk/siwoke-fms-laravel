<?php

declare(strict_types=1);

namespace Modules\Ticket;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Ticket\Application\Guards\RequiredEvidenceGuard;
use Modules\Ticket\Domain\Models\TicketVisit;

final class TicketServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Rules that must hold before a visit closes. Other modules add theirs (assets, ...) by tagging
        // a Modules\Ticket\Application\Contracts\ClosureGuard with this name.
        $this->app->tag([RequiredEvidenceGuard::class], 'ticket.closure_guards');
    }

    public function boot(): void
    {
        // Attachments store this alias instead of a class name, so renaming the class never orphans them.
        Relation::morphMap(['ticket_visit' => TicketVisit::class]);

        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        Route::middleware('api')
            ->prefix('api/mobile/v1')
            ->name('api.mobile.v1.')
            ->group(__DIR__.'/routes/api_v1.php');
    }
}
