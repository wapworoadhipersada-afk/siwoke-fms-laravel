<?php

declare(strict_types=1);

namespace Modules\Integration\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a vendor calls one of our ticket statuses.
 *
 * @property int $id
 * @property int $vendor_id
 * @property string $internal_status
 * @property string $vendor_status
 */
class StatusMapping extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = [];
}
