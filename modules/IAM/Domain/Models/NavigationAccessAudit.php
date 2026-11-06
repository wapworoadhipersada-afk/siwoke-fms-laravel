<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Before and after snapshots of a change to the sidebar or to what a role sees.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $event
 * @property string $subject_type
 * @property int|null $subject_id
 * @property array<mixed>|null $before
 * @property array<mixed>|null $after
 * @property Carbon|null $created_at
 */
final class NavigationAccessAudit extends Model
{
    public $timestamps = false;

    protected $table = 'navigation_access_audits';

    protected $fillable = [
        'actor_id', 'event', 'subject_type', 'subject_id',
        'before', 'after', 'ip_address', 'user_agent', 'created_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
