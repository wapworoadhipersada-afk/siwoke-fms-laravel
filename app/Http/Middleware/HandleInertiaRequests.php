<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;
use Modules\IAM\Application\Services\NavigationMenuService;
use Modules\IAM\Domain\Permissions;
use Modules\Integration\Domain\Models\OutboundEvent;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // Names only, so the UI can hide what the user may not do. The server still enforces every rule.
                'permissions' => $request->user()?->getAllPermissions()->pluck('name')->values()->all() ?? [],
            ],
            // The sidebar, already filtered for this person's roles and permissions (see NavigationMenuService).
            'navigation' => fn (): array => $request->user() instanceof User ? app(NavigationMenuService::class)->forUser($request->user()) : [],
            // Things worth interrupting someone for, shown in the bell of the header. Counted at most once a minute per person.
            'alerts' => fn (): array => $request->user() instanceof User ? $this->alerts($request->user()) : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return list<array{title: string, text: string, href: string}>
     */
    private function alerts(User $user): array
    {
        return Cache::remember("header-alerts:{$user->id}", 60, function () use ($user): array {
            $alerts = [];

            if ($user->can(Permissions::TICKET_VIEW)) {
                $overdue = Ticket::query()->visibleTo($user)
                    ->whereNotIn('status', [TicketStatus::Finished->value, TicketStatus::DoneNegative->value, TicketStatus::Cancelled->value, TicketStatus::Pending->value])
                    ->where('sla_due_at', '<', now())
                    ->count();

                if ($overdue > 0) {
                    $alerts[] = ['title' => "{$overdue} tiket lewat batas SLA", 'text' => 'Segera tangani atau jadwalkan ulang.', 'href' => route('tickets.index', absolute: false)];
                }
            }

            if ($user->can(Permissions::INTEGRATION_LOG_VIEW)) {
                $dead = OutboundEvent::query()->where('status', OutboundEvent::DEAD)->count();

                if ($dead > 0) {
                    $alerts[] = ['title' => "{$dead} callback vendor gagal", 'text' => 'Periksa log integrasi dan kirim ulang.', 'href' => route('integration.logs.index', absolute: false)];
                }
            }

            return $alerts;
        });
    }
}
