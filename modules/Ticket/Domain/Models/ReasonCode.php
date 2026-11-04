<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Shared\Domain\Concerns\HasPublicUlid;
use Modules\Ticket\Domain\Enums\ReasonType;

/**
 * A reason a technician picks when a visit ends in pending or done negative.
 *
 * @property int $id
 * @property string $ulid
 * @property ReasonType $type
 * @property string $code
 * @property string $name
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['type', 'code', 'name', 'is_active'])]
class ReasonCode extends Model
{
    use HasPublicUlid;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ReasonType::class,
            'is_active' => 'boolean',
        ];
    }
}
