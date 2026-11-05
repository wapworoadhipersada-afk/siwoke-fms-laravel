<?php

declare(strict_types=1);

namespace Modules\Integration\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One call between us and a vendor system. Append-only: the database refuses to change or delete it.
 *
 * @property int $id
 * @property string $direction
 * @property int|null $client_id
 * @property int|null $vendor_id
 * @property string $method
 * @property string $path
 * @property int|null $status_code
 * @property array<string, mixed> $request_headers
 * @property string|null $request_body
 * @property string|null $response_body
 * @property int|null $duration_ms
 * @property string $correlation_id
 * @property int|null $ticket_id
 * @property string|null $ip
 * @property string|null $error
 * @property Carbon|null $created_at
 */
class IntegrationLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'status_code' => 'integer',
            'duration_ms' => 'integer',
        ];
    }
}
