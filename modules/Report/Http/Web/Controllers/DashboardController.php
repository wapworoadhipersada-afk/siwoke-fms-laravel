<?php

declare(strict_types=1);

namespace Modules\Report\Http\Web\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Domain\Permissions;
use Modules\Integration\Domain\Models\OutboundEvent;
use Modules\Report\Application\Queries\AssetStatistics;
use Modules\Report\Application\Queries\TicketStatistics;
use Modules\Shared\Http\ModuleController;
use Modules\Ticket\Domain\Enums\TicketStatus;

/**
 * The first page after login. Each block appears only for people allowed to see that kind of data, and every
 * number is limited to the areas that person is responsible for.
 */
final class DashboardController extends ModuleController
{
    public function __invoke(TicketStatistics $tickets, AssetStatistics $assets): Response
    {
        $user = $this->user();

        return Inertia::render('Dashboard', [
            'tickets' => $user->can(Permissions::TICKET_VIEW) ? [
                ...$tickets->handle($user, withWorkload: $user->can(Permissions::TICKET_ASSIGN)),
                'status_labels' => collect(TicketStatus::cases())->mapWithKeys(fn (TicketStatus $status): array => [$status->value => $status->label()])->all(),
            ] : null,
            'assets' => $user->can(Permissions::ASSET_VIEW) ? $assets->handle($user) : null,
            'integration' => $user->can(Permissions::INTEGRATION_LOG_VIEW) ? [
                'waiting' => OutboundEvent::query()->where('status', OutboundEvent::PENDING)->count(),
                'dead' => OutboundEvent::query()->where('status', OutboundEvent::DEAD)->count(),
            ] : null,
            'performance_days' => (int) config('reports.performance_days'),
        ]);
    }
}
