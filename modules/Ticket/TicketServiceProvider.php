<?php

declare(strict_types=1);

namespace Modules\Ticket;

use Illuminate\Support\ServiceProvider;

final class TicketServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Other modules add rules that must hold before a visit closes (photos, assets) by tagging
        // a Modules\Ticket\Application\Contracts\ClosureGuard with this name.
        $this->app->tag([], 'ticket.closure_guards');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
