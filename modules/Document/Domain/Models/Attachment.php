<?php

declare(strict_types=1);

namespace Modules\Document\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Document\Domain\Enums\AttachmentKind;
use Modules\Shared\Domain\Concerns\HasPublicUlid;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An evidence file: a photo, a signature, or a generated document. The file itself sits on a
 * private disk under a random name; this row says what it is for, who sent it, and when and where.
 *
 * @property int $id
 * @property string $ulid
 * @property string $attachable_type
 * @property int $attachable_id
 * @property AttachmentKind $kind
 * @property string $disk
 * @property string $path
 * @property string|null $thumbnail_path
 * @property string|null $original_name
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property string $sha256
 * @property string $source_sha256
 * @property Carbon|null $captured_at
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int $uploaded_by
 * @property int|null $device_id
 * @property string|null $client_uuid
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['attachable_type', 'attachable_id', 'kind', 'disk', 'path', 'thumbnail_path', 'original_name', 'mime', 'size', 'width', 'height', 'sha256', 'source_sha256', 'captured_at', 'latitude', 'longitude', 'uploaded_by', 'device_id', 'client_uuid'])]
class Attachment extends Model
{
    use HasPublicUlid, LogsActivity, SoftDeletes;

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('document')
            ->logOnly(['attachable_type', 'attachable_id', 'kind', 'mime', 'size', 'sha256'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AttachmentKind::class,
            'captured_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }
}
