<?php

declare(strict_types=1);

namespace Modules\Document\Application\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Document\Domain\Contracts\HasAttachments;
use Modules\Document\Domain\Enums\AttachmentKind;
use Modules\Document\Domain\Models\Attachment;
use Throwable;

/**
 * Stores a file the system itself produced (a Berita Acara PDF). There is no uploader and nothing to
 * decode: the bytes are ours. It lands on the same private disk, under a random name, with its hash.
 */
final class StoreGeneratedDocument
{
    public function handle(Model&HasAttachments $attachable, AttachmentKind $kind, string $bytes, string $displayName, string $mime = 'application/pdf', string $extension = 'pdf'): Attachment
    {
        if (in_array($kind, AttachmentKind::uploadable(), true)) {
            throw new InvalidArgumentException('Only system-generated kinds may be stored this way.');
        }

        $disk = (string) config('documents.disk');
        $ulid = (string) Str::ulid();
        $path = 'attachments/'.now()->format('Y/m')."/{$ulid}.{$extension}";
        $hash = hash('sha256', $bytes);

        $storage = Storage::disk($disk);
        $storage->put($path, $bytes);

        try {
            $attachment = new Attachment([
                'attachable_type' => $attachable->getMorphClass(),
                'attachable_id' => $attachable->getKey(),
                'kind' => $kind,
                'disk' => $disk,
                'path' => $path,
                'original_name' => mb_substr($displayName, 0, 150),
                'mime' => $mime,
                'size' => strlen($bytes),
                'sha256' => $hash,
                'source_sha256' => $hash,
                'captured_at' => now(),
            ]);
            $attachment->forceFill(['ulid' => $ulid])->save();

            return $attachment;
        } catch (Throwable $exception) {
            $storage->delete($path);

            throw $exception;
        }
    }
}
