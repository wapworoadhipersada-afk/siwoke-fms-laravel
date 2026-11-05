<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NavigationAccessAudit extends Model
{
    public $timestamps = false;

    protected $table = 'navigation_access_audits';

    protected $fillable = [
        'actor_id', 'event', 'subject_type', 'subject_id',
        'before', 'after', 'ip_address', 'user_agent', 'created_at',
    ];

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
