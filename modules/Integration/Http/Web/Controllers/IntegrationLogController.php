<?php

declare(strict_types=1);

namespace Modules\Integration\Http\Web\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\IAM\Domain\Permissions;
use Modules\Integration\Application\Jobs\DeliverOutboundEvent;
use Modules\Integration\Domain\Models\IntegrationLog;
use Modules\Integration\Domain\Models\OutboundEvent;
use Modules\Shared\Http\ModuleController;

/**
 * Support's view of the conversation with vendor systems: every call, and the callbacks that are waiting or stuck.
 */
final class IntegrationLogController extends ModuleController
{
    public function index(Request $request): Response
    {
        $this->allow(Permissions::INTEGRATION_LOG_VIEW);

        $direction = in_array($request->query('direction'), ['in', 'out'], true) ? (string) $request->query('direction') : '';
        $outcome = in_array($request->query('outcome'), ['ok', 'failed'], true) ? (string) $request->query('outcome') : '';
        $search = trim((string) $request->query('q'));

        $logs = IntegrationLog::query()
            ->when($direction !== '', fn ($query) => $query->where('direction', $direction))
            ->when($outcome === 'ok', fn ($query) => $query->whereBetween('status_code', [200, 299]))
            ->when($outcome === 'failed', fn ($query) => $query->where(fn ($query) => $query->whereNull('status_code')->orWhereNotBetween('status_code', [200, 299])))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('path', 'ilike', "%{$search}%")
                ->orWhere('correlation_id', $search)
                ->orWhere('request_body', 'ilike', "%{$search}%")))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (IntegrationLog $log): array => [
                'id' => $log->id,
                'direction' => $log->direction,
                'method' => $log->method,
                'path' => $log->path,
                'status_code' => $log->status_code,
                'duration_ms' => $log->duration_ms,
                'correlation_id' => $log->correlation_id,
                'error' => $log->error,
                'request_body' => $log->request_body,
                'response_body' => $log->response_body,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        $stuck = OutboundEvent::query()->whereIn('status', [OutboundEvent::DEAD])->count();
        $waiting = OutboundEvent::query()->where('status', OutboundEvent::PENDING)->count();

        return Inertia::render('integration/logs/Index', [
            'logs' => $logs,
            'filters' => ['direction' => $direction, 'outcome' => $outcome, 'q' => $search],
            'outbound' => [
                'waiting' => $waiting,
                'stuck' => $stuck,
                'dead' => OutboundEvent::query()->where('status', OutboundEvent::DEAD)->with('client')->orderByDesc('id')->limit(20)->get()->map(fn (OutboundEvent $event): array => [
                    'ulid' => $event->ulid,
                    'client' => $event->client->name ?? '-',
                    'attempts' => $event->attempts,
                    'last_error' => $event->last_error,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'can' => ['retry' => $this->user()->can(Permissions::INTEGRATION_CLIENT_MANAGE)],
        ]);
    }

    public function retry(OutboundEvent $event): RedirectResponse
    {
        $this->allow(Permissions::INTEGRATION_CLIENT_MANAGE);

        abort_unless($event->status === OutboundEvent::DEAD, 404);

        $event->forceFill(['status' => OutboundEvent::PENDING, 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null])->save();
        DeliverOutboundEvent::dispatch($event->id);
        $this->success('Callback dikirim ulang.');

        return to_route('integration.logs.index');
    }
}
