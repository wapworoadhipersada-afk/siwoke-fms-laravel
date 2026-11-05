<?php

declare(strict_types=1);

namespace Modules\Document\Application\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Modules\Document\Domain\Contracts\HasAttachments;
use Modules\Document\Domain\Models\Attachment;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only way a file leaves storage: after asking the thing it documents whether this user may see it.
 */
final class AttachmentAccess
{
    public function canView(User $user, Attachment $attachment): bool
    {
        $attachable = $attachment->attachable;

        return $attachable instanceof Model && $attachable instanceof HasAttachments && $attachable->canViewAttachments($user);
    }

    /**
     * Streams the file (or its thumbnail) with headers that stop a browser from guessing a different type,
     * caching it in a shared place, or treating it as anything but an image.
     */
    public function respond(User $user, Attachment $attachment, bool $thumbnail = false): Response
    {
        abort_unless($this->canView($user, $attachment), 404);

        $path = $thumbnail && $attachment->thumbnail_path !== null ? $attachment->thumbnail_path : $attachment->path;
        $mime = $thumbnail ? 'image/jpeg' : $attachment->mime;
        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $attachment->ulid.($thumbnail ? '_thumb.jpg' : '.'.pathinfo($attachment->path, PATHINFO_EXTENSION)), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
