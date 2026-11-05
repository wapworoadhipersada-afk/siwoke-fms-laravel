<?php

declare(strict_types=1);

namespace Modules\Ticket\Http\Web\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Document\Domain\Models\Attachment;
use Modules\IAM\Domain\Enums\UserType;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Domain\Models\Terminal;
use Modules\Master\Domain\Models\Vendor;
use Modules\Shared\Http\ModuleController;
use Modules\Ticket\Application\Actions\AssignTicket;
use Modules\Ticket\Application\Actions\CancelTicket;
use Modules\Ticket\Application\Actions\CreateTicket;
use Modules\Ticket\Application\Actions\ReopenTicket;
use Modules\Ticket\Application\Actions\UnassignTicket;
use Modules\Ticket\Domain\Enums\TicketPriority;
use Modules\Ticket\Domain\Enums\TicketStatus;
use Modules\Ticket\Domain\Models\Ticket;
use Modules\Ticket\Domain\Models\TicketAssignment;
use Modules\Ticket\Domain\Models\TicketStatusHistory;
use Modules\Ticket\Domain\Models\TicketType;
use Modules\Ticket\Domain\Models\TicketVisit;
use Modules\Ticket\Http\Web\Requests\StoreTicketRequest;

final class TicketController extends ModuleController
{
    public function index(Request $request): Response
    {
        $this->allow(Permissions::TICKET_VIEW);

        $user = $this->user();
        $search = trim((string) $request->query('q'));
        $status = TicketStatus::tryFrom((string) $request->query('status'));
        $type = (string) $request->query('type');
        $vendor = (string) $request->query('vendor');
        $technician = (string) $request->query('technician');
        $overdue = $request->boolean('overdue');

        $tickets = Ticket::query()
            ->visibleTo($user)
            ->with(['type', 'vendor', 'terminal', 'site.merchant', 'technician'])
            ->when($status instanceof TicketStatus, fn ($query) => $query->where('tickets.status', $status?->value))
            ->when($type !== '', fn ($query) => $query->whereHas('type', fn ($query) => $query->where('ulid', $type)))
            ->when($vendor !== '', fn ($query) => $query->whereHas('vendor', fn ($query) => $query->where('ulid', $vendor)))
            ->when($technician !== '', fn ($query) => $query->whereHas('technician', fn ($query) => $query->where('ulid', $technician)))
            ->when($overdue, fn ($query) => $query
                ->whereNotIn('tickets.status', [TicketStatus::Finished->value, TicketStatus::DoneNegative->value, TicketStatus::Cancelled->value, TicketStatus::Pending->value])
                ->where('tickets.sla_due_at', '<', now()))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('ticket_no', 'ilike', "%{$search}%")
                ->orWhere('vendor_ticket_no', 'ilike', "%{$search}%")
                ->orWhereHas('terminal', fn ($query) => $query->where('tid', 'ilike', "%{$search}%"))
                ->orWhereHas('site.merchant', fn ($query) => $query->where('name', 'ilike', "%{$search}%"))))
            ->orderByDesc('tickets.id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Ticket $ticket): array => [
                'ulid' => $ticket->ulid,
                'ticket_no' => $ticket->ticket_no,
                'vendor_ticket_no' => $ticket->vendor_ticket_no,
                'type' => $ticket->type->name,
                'vendor' => $ticket->vendor->code,
                'tid' => $ticket->terminal->tid,
                'merchant' => $ticket->site->merchant->name,
                'priority' => $ticket->priority->value,
                'priority_label' => $ticket->priority->label(),
                'status' => $ticket->status->value,
                'status_label' => $ticket->status->label(),
                'technician' => $ticket->technician?->name,
                'sla_due_at' => $ticket->sla_due_at->toIso8601String(),
                'is_overdue' => $ticket->isOverdue(),
            ]);

        return Inertia::render('tickets/Index', [
            'tickets' => $tickets,
            'filters' => [
                'q' => $search,
                'status' => $status instanceof TicketStatus ? $status->value : '',
                'type' => $type,
                'vendor' => $vendor,
                'technician' => $technician,
                'overdue' => $overdue ? '1' : '',
            ],
            'statuses' => array_map(fn (TicketStatus $case): array => ['value' => $case->value, 'label' => $case->label()], TicketStatus::cases()),
            'types' => $this->typeOptions(),
            'vendors' => $this->vendorOptions(),
            'technicians' => $user->can(Permissions::TICKET_ASSIGN) ? $this->technicianOptions() : [],
            'can' => ['create' => $user->can(Permissions::TICKET_CREATE)],
        ]);
    }

    public function create(): Response
    {
        $this->allow(Permissions::TICKET_CREATE);

        return Inertia::render('tickets/Create', [
            'vendors' => $this->vendorOptions(),
            'types' => $this->typeOptions(),
            'priorities' => array_map(fn (TicketPriority $case): array => ['value' => $case->value, 'label' => $case->label()], TicketPriority::cases()),
        ]);
    }

    public function store(StoreTicketRequest $request, CreateTicket $action): RedirectResponse
    {
        $data = $request->validated();

        $vendor = Vendor::query()->where('ulid', (string) $data['vendor'])->first();
        $type = TicketType::query()->where('ulid', (string) $data['ticket_type'])->first();

        if (! $vendor instanceof Vendor) {
            throw ValidationException::withMessages(['vendor' => 'Vendor tidak ditemukan.']);
        }

        if (! $type instanceof TicketType) {
            throw ValidationException::withMessages(['ticket_type' => 'Jenis tiket tidak ditemukan.']);
        }

        // The TID is looked up inside the user's own area, so it cannot be used to probe other regions.
        $terminal = Terminal::query()
            ->visibleTo($this->user())
            ->where('vendor_id', $vendor->id)
            ->whereRaw('lower(tid) = ?', [mb_strtolower(trim((string) $data['tid']))])
            ->first();

        if (! $terminal instanceof Terminal) {
            throw ValidationException::withMessages(['tid' => "TID tidak ditemukan untuk vendor {$vendor->code} di wilayah Anda."]);
        }

        $ticket = $this->translatingErrors(['ticket_type' => 'ticket_type'], fn () => $action->handle(
            terminal: $terminal,
            type: $type,
            creator: $this->user(),
            priority: TicketPriority::from((string) $data['priority']),
            scheduledAt: isset($data['scheduled_at']) ? CarbonImmutable::parse((string) $data['scheduled_at']) : null,
            description: $data['description'] ?? null,
            vendorTicketNo: $data['vendor_ticket_no'] ?? null,
        ));

        $this->success("Tiket {$ticket->ticket_no} dibuat.");

        return to_route('tickets.show', $ticket);
    }

    public function show(Ticket $ticket): Response
    {
        $this->allow(Permissions::TICKET_VIEW);
        $this->visible($ticket);

        $ticket->load(['type', 'vendor', 'terminal', 'site.merchant', 'site.location', 'technician', 'creator']);
        $user = $this->user();
        $status = $ticket->status;

        $histories = TicketStatusHistory::query()->where('ticket_id', $ticket->id)->with('actor')->orderByDesc('id')->get();
        $visits = TicketVisit::query()->where('ticket_id', $ticket->id)->with(['technician', 'reasonCode'])->orderBy('visit_no')->get();
        $assignments = TicketAssignment::query()->where('ticket_id', $ticket->id)->with(['technician', 'assigner'])->orderBy('id')->get();

        $visitNumbers = $visits->pluck('visit_no', 'id');
        $files = Attachment::query()
            ->where('attachable_type', 'ticket_visit')
            ->whereIn('attachable_id', $visits->pluck('id'))
            ->with('uploader')
            ->orderBy('id')
            ->get();

        $canAssign = $user->can(Permissions::TICKET_ASSIGN) && in_array($status, [TicketStatus::Received, TicketStatus::Pending, TicketStatus::Assigned, TicketStatus::Accepted], true);

        return Inertia::render('tickets/Show', [
            'ticket' => [
                'ulid' => $ticket->ulid,
                'ticket_no' => $ticket->ticket_no,
                'vendor_ticket_no' => $ticket->vendor_ticket_no,
                'type' => $ticket->type->name,
                'vendor' => "{$ticket->vendor->code} — {$ticket->vendor->name}",
                'source' => $ticket->source->label(),
                'priority_label' => $ticket->priority->label(),
                'status' => $status->value,
                'status_label' => $status->label(),
                'description' => $ticket->description,
                'scheduled_at' => $ticket->scheduled_at?->toIso8601String(),
                'received_at' => $ticket->received_at->toIso8601String(),
                'sla_due_at' => $ticket->sla_due_at->toIso8601String(),
                'closed_at' => $ticket->closed_at?->toIso8601String(),
                'is_overdue' => $ticket->isOverdue(),
                'technician' => $ticket->technician?->name,
                'created_by' => $ticket->creator?->name,
                'tid' => $ticket->terminal->tid,
                'merchant' => ['ulid' => $ticket->site->merchant->ulid, 'name' => $ticket->site->merchant->name, 'phone' => $ticket->site->merchant->phone],
                'site' => [
                    'address' => $ticket->site->address,
                    'service_point' => $ticket->site->location->name,
                    'pic_name' => $ticket->site->pic_name,
                    'pic_phone' => $ticket->site->pic_phone,
                    'latitude' => $ticket->site->latitude,
                    'longitude' => $ticket->site->longitude,
                ],
            ],
            'timeline' => $histories->map(fn (TicketStatusHistory $history): array => [
                'event_label' => $history->event->label(),
                'to_status_label' => $history->to_status->label(),
                'actor' => $history->actor_id === null ? 'Sistem' : ($history->actor->name ?? 'Sistem'),
                'reason' => $history->reason,
                'occurred_at' => $history->occurred_at->toIso8601String(),
                'on_behalf' => (bool) ($history->meta['on_behalf'] ?? false),
                'outside_radius' => (bool) ($history->meta['outside_radius'] ?? false),
                'distance_m' => $history->meta['distance_m'] ?? null,
                'notes' => $history->meta['notes'] ?? null,
            ])->values()->all(),
            'visits' => $visits->map(fn (TicketVisit $visit): array => [
                'visit_no' => $visit->visit_no,
                'technician' => $visit->technician->name,
                'scheduled_at' => $visit->scheduled_at?->toIso8601String(),
                'arrived_at' => $visit->arrived_at?->toIso8601String(),
                'closed_at' => $visit->closed_at?->toIso8601String(),
                'outcome_label' => $visit->outcome?->label() ?? 'Berjalan',
                'reason' => $visit->reasonCode?->name,
                'notes' => $visit->notes,
                'distance_m' => $visit->arrival_distance_m,
            ])->values()->all(),
            'attachments' => $files->map(fn (Attachment $file): array => [
                'visit_no' => (int) ($visitNumbers[$file->attachable_id] ?? 0),
                'kind' => $file->kind->value,
                'kind_label' => $file->kind->label(),
                'uploader' => $file->uploader->name,
                'captured_at' => $file->captured_at?->toIso8601String(),
                'uploaded_at' => $file->created_at?->toIso8601String(),
                'width' => $file->width,
                'height' => $file->height,
                'latitude' => $file->latitude,
                'longitude' => $file->longitude,
                'url' => route('attachments.show', $file, false),
                'thumbnail_url' => route('attachments.thumbnail', $file, false),
            ])->values()->all(),
            'assignments' => $assignments->map(fn (TicketAssignment $assignment): array => [
                'technician' => $assignment->technician->name,
                'assigned_by' => $assignment->assigner->name,
                'assigned_at' => $assignment->assigned_at->toIso8601String(),
                'unassigned_at' => $assignment->unassigned_at?->toIso8601String(),
                'unassign_reason' => $assignment->unassign_reason,
            ])->values()->all(),
            'technicians' => $canAssign ? $this->technicianOptions() : [],
            'can' => [
                'assign' => $canAssign,
                'unassign' => $user->can(Permissions::TICKET_ASSIGN) && in_array($status, [TicketStatus::Assigned, TicketStatus::Accepted], true),
                'cancel' => $user->can(Permissions::TICKET_CANCEL) && in_array($status, [TicketStatus::Received, TicketStatus::Assigned, TicketStatus::Accepted, TicketStatus::OnTheWay, TicketStatus::Pending], true),
                'reopen' => $user->can(Permissions::TICKET_REOPEN) && in_array($status, [TicketStatus::Finished, TicketStatus::DoneNegative], true),
            ],
        ]);
    }

    public function assign(Request $request, Ticket $ticket, AssignTicket $action): RedirectResponse
    {
        $this->allow(Permissions::TICKET_ASSIGN);
        $this->visible($ticket);

        $data = $request->validate([
            'technician' => ['required', 'string', 'size:26'],
            'scheduled_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $technician = User::query()->where('ulid', $data['technician'])->first();

        if (! $technician instanceof User) {
            throw ValidationException::withMessages(['technician' => 'Teknisi tidak ditemukan.']);
        }

        $action->handle($ticket, $technician, $this->user(), isset($data['scheduled_at']) ? CarbonImmutable::parse($data['scheduled_at']) : null, $data['reason'] ?? null);
        $this->success("Tiket ditugaskan ke {$technician->name}.");

        return back();
    }

    public function unassign(Request $request, Ticket $ticket, UnassignTicket $action): RedirectResponse
    {
        $this->allow(Permissions::TICKET_ASSIGN);
        $this->visible($ticket);

        $action->handle($ticket, $this->user(), (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason']);
        $this->success('Penugasan dicabut.');

        return back();
    }

    public function cancel(Request $request, Ticket $ticket, CancelTicket $action): RedirectResponse
    {
        $this->allow(Permissions::TICKET_CANCEL);
        $this->visible($ticket);

        $action->handle($ticket, $this->user(), (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason']);
        $this->success('Tiket dibatalkan.');

        return back();
    }

    public function reopen(Request $request, Ticket $ticket, ReopenTicket $action): RedirectResponse
    {
        $this->allow(Permissions::TICKET_REOPEN);
        $this->visible($ticket);

        $action->handle($ticket, $this->user(), (string) $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason']);
        $this->success('Tiket dibuka kembali.');

        return back();
    }

    private function visible(Ticket $ticket): void
    {
        abort_unless(Ticket::query()->visibleTo($this->user())->whereKey($ticket->id)->exists(), 404);
    }

    /**
     * @return array<int, array{ulid: string, code: string, name: string}>
     */
    private function vendorOptions(): array
    {
        return Vendor::query()->where('is_active', true)->orderBy('name')->get()
            ->map(fn (Vendor $vendor): array => ['ulid' => $vendor->ulid, 'code' => $vendor->code, 'name' => $vendor->name])
            ->values()->all();
    }

    /**
     * @return array<int, array{ulid: string, name: string, sla_hours: int}>
     */
    private function typeOptions(): array
    {
        return TicketType::query()->where('is_active', true)->orderBy('name')->get()
            ->map(fn (TicketType $type): array => ['ulid' => $type->ulid, 'name' => $type->name, 'sla_hours' => $type->sla_hours])
            ->values()->all();
    }

    /**
     * @return array<int, array{ulid: string, label: string}>
     */
    private function technicianOptions(): array
    {
        return User::query()
            ->permission(Permissions::TICKET_WORK)
            ->where('user_type', UserType::Technician->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(fn (User $technician): array => ['ulid' => $technician->ulid, 'label' => $technician->name])
            ->values()->all();
    }
}
