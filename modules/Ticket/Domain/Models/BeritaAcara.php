<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Document\Domain\Models\Attachment;
use Modules\Shared\Domain\Concerns\HasPublicUlid;
use Modules\Ticket\Domain\Enums\VisitOutcome;

/**
 * The official report of one visit. The text is frozen in `snapshot` at the moment it was made, so changing
 * a merchant's name or a technician's account later never alters a report already handed to the vendor.
 * Database triggers refuse any change to its content; a correction is a new revision.
 *
 * @property int $id
 * @property string $ulid
 * @property string $ba_number
 * @property int $revision
 * @property int $ticket_id
 * @property int $visit_id
 * @property int $attachment_id
 * @property VisitOutcome $outcome
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_sha256
 * @property bool $is_current
 * @property string|null $regenerate_reason
 * @property int|null $generated_by
 * @property Carbon $generated_at
 * @property Carbon|null $created_at
 */
class BeritaAcara extends Model
{
    use HasPublicUlid;

    public const UPDATED_AT = null;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_current' => true];

    protected $table = 'berita_acaras';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * BA/BRI/2026/11/000123 for the first revision, "… rev 2" for a correction.
     */
    public function label(): string
    {
        return $this->revision > 1 ? "{$this->ba_number} rev {$this->revision}" : $this->ba_number;
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<TicketVisit, $this>
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(TicketVisit::class, 'visit_id');
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => VisitOutcome::class,
            'snapshot' => 'array',
            'is_current' => 'boolean',
            'revision' => 'integer',
            'generated_at' => 'datetime',
        ];
    }
}
