<?php

declare(strict_types=1);

namespace Modules\Document\Application\Actions;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Document\Application\DTOs\ProcessedImage;
use Modules\Document\Application\Services\ImageProcessor;
use Modules\Document\Domain\Contracts\HasAttachments;
use Modules\Document\Domain\Enums\AttachmentKind;
use Modules\Document\Domain\Models\Attachment;
use Throwable;

/**
 * Accepts one evidence file for something (a ticket visit). The caller supplies the thing and the
 * person; this decides whether they may, cleans the image, stores it under a name of our own, and
 * records who sent it, when it was taken and where.
 */
final class StoreAttachment
{
    public function __construct(private readonly ImageProcessor $images) {}

    public function handle(
        Model&HasAttachments $attachable,
        User $uploader,
        UploadedFile $file,
        AttachmentKind $kind,
        ?CarbonInterface $capturedAt = null,
        string|float|null $latitude = null,
        string|float|null $longitude = null,
        ?int $deviceId = null,
        ?string $clientUuid = null,
    ): Attachment {
        if (! in_array($kind, AttachmentKind::uploadable(), true)) {
            throw ValidationException::withMessages(['kind' => 'Jenis lampiran ini tidak dapat diunggah.']);
        }

        if (! $attachable->canAttachFiles($uploader)) {
            throw new AuthorizationException('Anda tidak dapat menambahkan lampiran di sini.');
        }

        // A phone resending after a bad connection gets the original back, never a second copy.
        if ($clientUuid !== null) {
            $existing = Attachment::query()->where('client_uuid', $clientUuid)->first();

            if ($existing instanceof Attachment) {
                if ($existing->attachable_type !== $attachable->getMorphClass() || $existing->attachable_id !== $attachable->getKey()) {
                    throw ValidationException::withMessages(['client_uuid' => 'Kode unggahan ini sudah dipakai untuk lampiran lain.']);
                }

                return $existing;
            }
        }

        $this->assertCaptureTime($capturedAt);
        $bytes = $this->read($file);
        $sourceHash = hash('sha256', $bytes);

        $this->assertRoom($attachable, $kind);
        $this->assertNotRepeated($attachable, $sourceHash);

        $image = $this->images->process($bytes, $kind);

        return $this->persist($attachable, $uploader, $file, $kind, $image, $sourceHash, $capturedAt, $latitude, $longitude, $deviceId, $clientUuid);
    }

    private function read(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'Unggahan gagal diterima. Coba lagi.']);
        }

        $maxBytes = (int) config('documents.max_upload_kilobytes') * 1024;

        if (($file->getSize() ?: 0) > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'Ukuran file melebihi '.(int) config('documents.max_upload_kilobytes').' KB.']);
        }

        // A temporary file can vanish between the upload and now (a dropped connection, a scanner); that is the
        // sender's problem to retry, not a server error.
        $path = $file->getRealPath();
        $bytes = $path === false ? false : @file_get_contents($path);

        if ($bytes === false || $bytes === '') {
            throw ValidationException::withMessages(['file' => 'File kosong atau tidak dapat dibaca. Coba unggah lagi.']);
        }

        return $bytes;
    }

    private function assertCaptureTime(?CarbonInterface $capturedAt): void
    {
        if (! $capturedAt instanceof CarbonInterface) {
            return;
        }

        if ($capturedAt->greaterThan(now()->addMinutes((int) config('documents.max_capture_future_minutes')))) {
            throw ValidationException::withMessages(['captured_at' => 'Waktu pengambilan tidak boleh di masa depan. Periksa jam perangkat.']);
        }

        if ($capturedAt->lessThan(now()->subDays((int) config('documents.max_capture_age_days')))) {
            throw ValidationException::withMessages(['captured_at' => 'Waktu pengambilan terlalu lama.']);
        }
    }

    /**
     * Photos are capped per kind; a signature is a single item, so a new one replaces the old.
     */
    private function assertRoom(Model&HasAttachments $attachable, AttachmentKind $kind): void
    {
        if ($kind->isSignature()) {
            return;
        }

        $count = $attachable->attachments()->where('kind', $kind->value)->count();

        if ($count >= (int) config('documents.max_photos_per_kind')) {
            throw ValidationException::withMessages(['file' => "Maksimal {$this->max()} {$this->lower($kind->label())}."]);
        }
    }

    private function assertNotRepeated(Model&HasAttachments $attachable, string $sourceHash): void
    {
        if ($attachable->attachments()->where('source_sha256', $sourceHash)->exists()) {
            throw ValidationException::withMessages(['file' => 'File ini sudah pernah diunggah untuk kunjungan ini.']);
        }
    }

    private function persist(
        Model&HasAttachments $attachable,
        User $uploader,
        UploadedFile $file,
        AttachmentKind $kind,
        ProcessedImage $image,
        string $sourceHash,
        ?CarbonInterface $capturedAt,
        string|float|null $latitude,
        string|float|null $longitude,
        ?int $deviceId,
        ?string $clientUuid,
    ): Attachment {
        $disk = (string) config('documents.disk');
        $ulid = (string) Str::ulid();
        $directory = 'attachments/'.now()->format('Y/m');
        $path = "{$directory}/{$ulid}.{$image->extension}";
        $thumbnailPath = "{$directory}/{$ulid}_thumb.jpg";

        $storage = Storage::disk($disk);
        $storage->put($path, $image->bytes);
        $storage->put($thumbnailPath, $image->thumbnailBytes);

        try {
            return DB::transaction(function () use ($attachable, $uploader, $file, $kind, $image, $sourceHash, $capturedAt, $latitude, $longitude, $deviceId, $clientUuid, $disk, $ulid, $path, $thumbnailPath): Attachment {
                if ($kind->isSignature()) {
                    // The latest signature is the one that counts; the earlier one stays on record as deleted.
                    $attachable->attachments()->where('kind', $kind->value)->get()->each->delete();
                }

                $attachment = new Attachment([
                    'attachable_type' => $attachable->getMorphClass(),
                    'attachable_id' => $attachable->getKey(),
                    'kind' => $kind,
                    'disk' => $disk,
                    'path' => $path,
                    'thumbnail_path' => $thumbnailPath,
                    'original_name' => $this->displayName($file),
                    'mime' => $image->mime,
                    'size' => strlen($image->bytes),
                    'width' => $image->width,
                    'height' => $image->height,
                    'sha256' => hash('sha256', $image->bytes),
                    'source_sha256' => $sourceHash,
                    'captured_at' => $capturedAt,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'uploaded_by' => $uploader->id,
                    'device_id' => $deviceId,
                    'client_uuid' => $clientUuid,
                ]);
                $attachment->forceFill(['ulid' => $ulid])->save();

                return $attachment;
            });
        } catch (Throwable $exception) {
            // The row was not saved, so the files would be orphans nobody could ever find.
            $storage->delete([$path, $thumbnailPath]);

            throw $exception;
        }
    }

    /**
     * The uploader's file name, made safe to display. It is never used to build a path.
     */
    private function displayName(UploadedFile $file): ?string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '', $name) ?? '';
        $name = trim(mb_substr($name, 0, 120));

        return $name === '' ? null : $name;
    }

    private function max(): int
    {
        return (int) config('documents.max_photos_per_kind');
    }

    private function lower(string $text): string
    {
        return mb_strtolower($text);
    }
}
