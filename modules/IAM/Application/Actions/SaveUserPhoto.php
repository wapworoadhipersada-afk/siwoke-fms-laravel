<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Document\Application\Services\ImageProcessor;
use Modules\Shared\Support\Audit;
use Throwable;

/**
 * Sets (or removes) a person's profile picture. The upload is never kept: it is decoded and re-drawn by
 * ImageProcessor as a small square JPEG under a name of our own, on the private disk, so a picture can never
 * carry hidden data or choose where it is stored. The old picture is deleted only after the new one is saved.
 */
final class SaveUserPhoto
{
    public function __construct(private readonly ImageProcessor $images) {}

    public function handle(User $user, UploadedFile $file, User $actor): User
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['photo' => 'Unggahan gagal diterima. Coba lagi.']);
        }

        $maxBytes = (int) config('documents.max_upload_kilobytes') * 1024;

        if (($file->getSize() ?: 0) > $maxBytes) {
            throw ValidationException::withMessages(['photo' => 'Ukuran foto melebihi '.(int) config('documents.max_upload_kilobytes').' KB.']);
        }

        $path = $file->getRealPath();
        $bytes = $path === false ? false : @file_get_contents($path);

        if ($bytes === false || $bytes === '') {
            throw ValidationException::withMessages(['photo' => 'File kosong atau tidak dapat dibaca.']);
        }

        try {
            $image = $this->images->avatar($bytes);
        } catch (ValidationException $exception) {
            // The image rules speak about "file"; here the field on screen is the photo.
            throw ValidationException::withMessages(['photo' => (string) collect($exception->errors())->flatten()->first()]);
        }

        $disk = Storage::disk((string) config('documents.disk'));
        $name = 'avatars/'.now()->format('Y/m').'/'.Str::ulid();
        $new = ["{$name}.jpg", "{$name}_thumb.jpg"];
        $old = array_filter([$user->photo_path, $user->photo_thumb_path]);

        $disk->put($new[0], $image->bytes);
        $disk->put($new[1], $image->thumbnailBytes);

        try {
            DB::transaction(function () use ($user, $new, $actor): void {
                $user->forceFill(['photo_path' => $new[0], 'photo_thumb_path' => $new[1]])->save();
                Audit::record('iam', 'photo_changed', "Foto profil diubah: {$user->email}", $actor, $user);
            });
        } catch (Throwable $exception) {
            $disk->delete($new);

            throw $exception;
        }

        if ($old !== []) {
            $disk->delete(array_values($old));
        }

        return $user;
    }

    public function remove(User $user, User $actor): void
    {
        $old = array_filter([$user->photo_path, $user->photo_thumb_path]);

        $user->forceFill(['photo_path' => null, 'photo_thumb_path' => null])->save();
        Audit::record('iam', 'photo_removed', "Foto profil dihapus: {$user->email}", $actor, $user);

        if ($old !== []) {
            Storage::disk((string) config('documents.disk'))->delete(array_values($old));
        }
    }
}
