<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\IAM\Domain\Permissions;
use Modules\Master\Application\Services\LocationAccess;
use Modules\Master\Application\Services\VendorAccess;
use Modules\Master\Domain\Models\MerchantSite;
use Modules\Master\Domain\Models\Terminal;
use Modules\Master\Domain\Models\Vendor;
use Modules\Shared\Domain\Concerns\HasPublicUlid;
use Modules\Ticket\Domain\Enums\TicketPriority;
use Modules\Ticket\Domain\Enums\TicketSource;
use Modules\Ticket\Domain\Enums\TicketStatus;

/**
 * A job at one merchant terminal. Its status is a snapshot; the status history is the record.
 *
 * @property int $id
 * @property string $ulid
 * @property string $ticket_no
 * @property string|null $vendor_ticket_no
 * @property int $vendor_id
 * @property int $terminal_id
 * @property int $merchant_site_id
 * @property int $ticket_type_id
 * @property TicketSource $source
 * @property TicketPriority $priority
 * @property TicketStatus $status
 * @property int|null $technician_id
 * @property string|null $description
 * @property Carbon|null $scheduled_at
 * @property Carbon $received_at
 * @property Carbon $sla_due_at
 * @property Carbon|null $closed_at
 * @property string|null $final_result
 * @property array<string, mixed> $payload
 * @property int|null $created_by
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['vendor_ticket_no', 'vendor_id', 'terminal_id', 'merchant_site_id', 'ticket_type_id', 'source', 'priority', 'description', 'scheduled_at', 'received_at', 'sla_due_at', 'payload', 'created_by'])]
class Ticket extends Model
{
    use HasPublicUlid, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'received',
        'source' => 'manual',
        'priority' => 'normal',
    ];

    public function isOverdue(): bool
    {
        return ! $this->status->isFinal() && $this->status !== TicketStatus::Pending && $this->sla_due_at->isPast();
    }

    /**
     * @return BelongsTo<TicketType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'ticket_type_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * @return BelongsTo<MerchantSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(MerchantSite::class, 'merchant_site_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<TicketVisit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(TicketVisit::class);
    }

    /**
     * @return HasMany<TicketStatusHistory, $this>
     */
    public function histories(): HasMany
    {
        return $this->hasMany(TicketStatusHistory::class);
    }

    /**
     * @return HasMany<TicketAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TicketAssignment::class);
    }

    /**
     * Tickets the user may see. Office users see the tickets at sites in their assigned areas;
     * a technician sees only the tickets they are, or were, assigned to. Users with all-areas access see everything.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query = app(VendorAccess::class)->constrain($query, $user, 'tickets.vendor_id');

        if (app(LocationAccess::class)->isUnrestricted($user)) {
            return $query;
        }

        $isOffice = $user->can(Permissions::TICKET_CREATE) || $user->can(Permissions::TICKET_ASSIGN) || $user->can(Permissions::TICKET_UPDATE);

        return $query->where(function (Builder $query) use ($user, $isOffice): void {
            $query->whereIn('tickets.id', TicketVisit::query()->where('technician_id', $user->id)->select('ticket_id'));

            if ($isOffice) {
                $query->orWhereIn('tickets.merchant_site_id', MerchantSite::query()->visibleTo($user)->select('merchant_sites.id'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'source' => TicketSource::class,
            'scheduled_at' => 'datetime',
            'received_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'closed_at' => 'datetime',
            'payload' => 'array',
            'version' => 'integer',
        ];
    }
}
