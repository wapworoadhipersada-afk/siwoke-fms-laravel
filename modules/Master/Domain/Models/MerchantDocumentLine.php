<?php

declare(strict_types=1);

namespace Modules\Master\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One merchant + TID on a document, exactly as the principal wrote it, plus what the system made of it.
 *
 * @property int $id
 * @property int $merchant_document_id
 * @property int $line_no
 * @property string $merchant_name
 * @property string $tid
 * @property string|null $mid
 * @property string|null $address
 * @property string|null $province
 * @property string|null $service_point
 * @property string|null $area
 * @property string|null $tid_note
 * @property int|null $location_id
 * @property string $status
 * @property string|null $error
 * @property int|null $terminal_id
 */
#[Fillable(['merchant_document_id', 'line_no', 'merchant_name', 'tid', 'mid', 'address', 'province', 'service_point', 'area', 'tid_note', 'location_id', 'status', 'error', 'terminal_id'])]
class MerchantDocumentLine extends Model
{
    public const PENDING = 'pending';

    public const ERROR = 'error';

    public const IMPORTED = 'imported';

    /**
     * @return BelongsTo<MerchantDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(MerchantDocument::class, 'merchant_document_id');
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }
}
