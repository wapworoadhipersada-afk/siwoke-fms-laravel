<?php

declare(strict_types=1);

namespace Modules\Ticket\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Shared\Domain\Concerns\HasPublicUlid;

/**
 * @property int $id
 * @property string $ulid
 * @property string $code
 * @property string $name
 * @property int $sla_hours
 * @property array<string, mixed> $form_schema
 * @property array<string, mixed> $evidence_rules
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'sla_hours', 'form_schema', 'evidence_rules', 'is_active'])]
class TicketType extends Model
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
            'form_schema' => 'array',
            'evidence_rules' => 'array',
            'is_active' => 'boolean',
            'sla_hours' => 'integer',
        ];
    }
}
