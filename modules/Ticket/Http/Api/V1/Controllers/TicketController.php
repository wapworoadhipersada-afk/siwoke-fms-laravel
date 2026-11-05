<?php

declare(strict_types=1);

namespace Modules\Ticket\Http\Api\V1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\IAM\Domain\Permissions;
use Modules\Shared\Http\ProblemException;
use Modules\Ticket\Application\Actions\AcceptTicket;
use Modules\Ticket\Application\Actions\ArriveAtTicket;
use Modules\Ticket\Application\Actions\CloseVisit;
use Modules\Ticket\Application\Actions\DepartForTicket;
use Modules\Ticket\Application\Actions\RejectTicket;
use Modules\Ticket\Application\Actions\StartHandling;
use Modules\Ticket\Application\DTOs\ActionContext;
use Modules\Ticket\Application\Services\EvidenceRules;
use Modules\Ticket\Application\Services\TechnicianActions;
use Modules\Ticket\Domain\Enums\ReasonType;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Enums\VisitOutcome;
use Modules\Ticket\Domain\Models\ReasonCode;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketVisit;
use Modules\Ticket\Http\Api\V1\Requests\CloseVisitRequest;
use Modules\Ticket\Http\Api\V1\Requests\RejectTicketRequest;
use Modules\Ticket\Http\Api\V1\Requests\TechnicianActionRequest;

/**
 * The technician's side of tickets: what is on my plate, and moving each job through its visit.
 */
final class TicketController extends Controller
{
    public function __construct(private readonly EvidenceRules $evidence) {}

    /**
     * My jobs. `scope=active` (default) is what I am responsible for now; `scope=history` is what I finished or paused.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->technician($request);

        $query = Ticket::query()->with(['type', 'vendor', 'terminal', 'site.location', 'site.merchant']);

        if ($request->query('scope') === 'history') {
            $query->whereHas('visits', fn ($visits) => $visits->where('technician_id', $user->id)->whereNotNull('outcome'))
                ->whereIn('status', [TicketStatus::Pending->value, TicketStatus::Finished->value, TicketStatus::DoneNegative->value])
                ->orderByDesc('updated_at')
                ->limit(50);
        } else {
            $query->where('technician_id', $user->id)->orderBy('scheduled_at')->orderBy('id');
        }

        return response()->json(['data' => $query->get()->map(fn (Ticket $ticket): array => $this->payload($ticket, $user))->values()->all()]);
    }

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $this->technician($request);

        return response()->json(['data' => $this->payload($this->mine($ticket, $user), $user)]);
    }

    /**
     * The reasons to offer when a visit ends pending or done negative.
     */
    public function reasons(Request $request): JsonResponse
    {
        $this->technician($request);

        return response()->json(['data' => ReasonCode::query()->where('is_active', true)->orderBy('type')->orderBy('name')->get()
            ->map(fn (ReasonCode $reason): array => ['id' => $reason->ulid, 'type' => $reason->type->value, 'code' => $reason->code, 'name' => $reason->name])
            ->values()->all()]);
    }

    public function accept(TechnicianActionRequest $request, Ticket $ticket, AcceptTicket $action): JsonResponse
    {
        $user = $this->technician($request);

        return $this->respond($ticket, $user, fn () => $action->handle($this->mine($ticket, $user), $user, $this->context($request, $user)));
    }

    public function reject(RejectTicketRequest $request, Ticket $ticket, RejectTicket $action): JsonResponse
    {
        $user = $this->technician($request);

        return $this->respond($ticket, $user, fn () => $action->handle($this->mine($ticket, $user), $user, (string) $request->validated('reason'), $this->context($request, $user)));
    }

    public function depart(TechnicianActionRequest $request, Ticket $ticket, DepartForTicket $action): JsonResponse
    {
        $user = $this->technician($request);

        return $this->respond($ticket, $user, fn () => $action->handle($this->mine($ticket, $user), $user, $this->context($request, $user)));
    }

    public function arrive(TechnicianActionRequest $request, Ticket $ticket, ArriveAtTicket $action): JsonResponse
    {
        $user = $this->technician($request);

        return $this->respond($ticket, $user, fn () => $action->handle($this->mine($ticket, $user), $user, $this->context($request, $user)));
    }

    public function start(TechnicianActionRequest $request, Ticket $ticket, StartHandling $action): JsonResponse
    {
        $user = $this->technician($request);

        return $this->respond($ticket, $user, fn () => $action->handle($this->mine($ticket, $user), $user, $this->context($request, $user)));
    }

    public function close(CloseVisitRequest $request, Ticket $ticket, CloseVisit $action): JsonResponse
    {
        $user = $this->technician($request);
        $outcome = VisitOutcome::from((string) $request->validated('result'));

        $reason = null;

        if ($outcome !== VisitOutcome::Finish) {
            $reason = ReasonCode::query()->where('ulid', (string) $request->validated('reason_code'))->first();

            if (! $reason instanceof ReasonCode) {
                throw ValidationException::withMessages(['reason_code' => 'Alasan tidak ditemukan.']);
            }
        }

        return $this->respond($ticket, $user, fn () => $action->handle(
            $this->mine($ticket, $user),
            $user,
            $outcome,
            $this->context($request, $user),
            $reason,
            $request->validated('notes'),
            (array) ($request->validated('data') ?? []),
        ));
    }

    /**
     * Runs an action and returns the fresh ticket. A job that is not yours is reported as a stable error code.
     *
     * @param  callable(): Ticket  $run
     */
    private function respond(Ticket $ticket, User $user, callable $run): JsonResponse
    {
        try {
            $run();
        } catch (AuthorizationException) {
            throw ProblemException::forbidden('not_assigned', 'Tiket ini bukan tugas Anda.');
        }

        return response()->json(['data' => $this->payload($ticket->fresh() ?? $ticket, $user)]);
    }

    private function technician(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        abort_unless($user->can(Permissions::TICKET_WORK), 403);

        return $user;
    }

    /**
     * A technician only ever sees tickets they have been assigned to; anything else does not exist for them.
     */
    private function mine(Ticket $ticket, User $user): Ticket
    {
        abort_unless(TicketVisit::query()->where('ticket_id', $ticket->id)->where('technician_id', $user->id)->exists(), 404);

        return $ticket;
    }

    private function context(Request $request, User $user): ActionContext
    {
        $token = $user->currentAccessToken();
        $deviceId = $token instanceof PersonalAccessToken ? $token->getAttribute('device_id') : null;
        $occurredAt = $request->input('occurred_at');

        return new ActionContext(
            occurredAt: is_string($occurredAt) && $occurredAt !== '' ? CarbonImmutable::parse($occurredAt) : null,
            latitude: $request->input('latitude'),
            longitude: $request->input('longitude'),
            accuracy: $request->filled('accuracy') ? (float) $request->input('accuracy') : null,
            deviceId: is_numeric($deviceId) ? (int) $deviceId : null,
            clientUuid: $request->filled('client_uuid') ? (string) $request->input('client_uuid') : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Ticket $ticket, User $user): array
    {
        $ticket->loadMissing(['type', 'vendor', 'terminal', 'site.location', 'site.merchant']);

        $visit = TicketVisit::query()->where('ticket_id', $ticket->id)->where('technician_id', $user->id)->orderByDesc('visit_no')->first();
        $site = $ticket->site;

        return [
            'id' => $ticket->ulid,
            'ticket_no' => $ticket->ticket_no,
            'vendor_ticket_no' => $ticket->vendor_ticket_no,
            'type' => ['code' => $ticket->type->code, 'name' => $ticket->type->name],
            'vendor' => $ticket->vendor->code,
            'priority' => $ticket->priority->value,
            'status' => $ticket->status->value,
            'description' => $ticket->description,
            'scheduled_at' => $ticket->scheduled_at?->toIso8601String(),
            'sla_due_at' => $ticket->sla_due_at->toIso8601String(),
            'is_overdue' => $ticket->isOverdue(),
            'terminal' => ['tid' => $ticket->terminal->tid],
            'merchant' => [
                'name' => $site->merchant->name,
                'owner_name' => $site->merchant->owner_name,
                'phone' => $site->merchant->phone,
            ],
            'site' => [
                'name' => $site->name,
                'address' => $site->address,
                'latitude' => $site->latitude,
                'longitude' => $site->longitude,
                'pic_name' => $site->pic_name,
                'pic_phone' => $site->pic_phone,
                'service_point' => $site->location->name,
            ],
            'visit' => $visit instanceof TicketVisit ? [
                'visit_no' => $visit->visit_no,
                'accepted_at' => $visit->accepted_at?->toIso8601String(),
                'departed_at' => $visit->departed_at?->toIso8601String(),
                'arrived_at' => $visit->arrived_at?->toIso8601String(),
                'handling_started_at' => $visit->handling_started_at?->toIso8601String(),
                'closed_at' => $visit->closed_at?->toIso8601String(),
                'outcome' => $visit->outcome?->value,
            ] : null,
            // Only while it is mine and still open; a closed ticket offers nothing.
            'actions' => $ticket->technician_id === $user->id ? TechnicianActions::for($ticket->status) : [],
            'reason_types' => [ReasonType::Pending->value, ReasonType::Negative->value],
            // What each result needs attached before it is accepted, and how much is already there.
            'evidence' => $visit instanceof TicketVisit && $visit->isOpen() ? $this->evidence->summary($ticket, $visit) : null,
        ];
    }
}
