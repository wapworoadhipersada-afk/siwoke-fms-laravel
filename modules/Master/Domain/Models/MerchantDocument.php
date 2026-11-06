<?php

declare(strict_types=1);

namespace Modules\Master\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Master\Application\Services\VendorAccess;
use Modules\Shared\Domain\Concerns\HasPublicUlid;

/**
 * A document sent by the principal listing merchants and their TIDs. Draft until imported.
 *
 * @property int $id
 * @property string $ulid
 * @property int $vendor_id
 * @property string $document_number
 * @property Carbon $received_on
 * @property string|null $notes
 * @property string $source
 * @property string|null $source_name
 * @property string $status
 * @property int|null $created_by
 * @property Carbon|null $imported_at
 */
#[Fillable(['vendor_id', 'document_number', 'received_on', 'notes', 'source', 'source_name', 'status', 'created_by', 'imported_by', 'imported_at'])]
class MerchantDocument extends Model
{
    use HasPublicUlid;

    public const DRAFT = 'draft';

    public const IMPORTED = 'imported';

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<MerchantDocumentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(MerchantDocumentLine::class)->orderBy('line_no');
    }

    /**
     * @param  Builder<MerchantDocument>  $query
     * @return Builder<MerchantDocument>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(VendorAccess::class)->constrain($query, $user, 'merchant_documents.vendor_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['received_on' => 'date', 'imported_at' => 'datetime'];
    }
}
