<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Who a ticket was handed to, by whom, and until when.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $visit_id
 * @property int $technician_id
 * @property int $assigned_by
 * @property Carbon $assigned_at
 * @property Carbon|null $unassigned_at
 * @property string|null $unassign_reason
 */
#[Fillable(['ticket_id', 'visit_id', 'technician_id', 'assigned_by', 'assigned_at', 'unassigned_at', 'unassign_reason'])]
class TicketAssignment extends Model
{
    public $timestamps = false;

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
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'unassigned_at' => 'datetime',
        ];
    }
}
