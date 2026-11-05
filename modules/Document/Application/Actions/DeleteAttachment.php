<?php

declare(strict_types=1);

namespace Modules\Document\Application\Actions;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Document\Domain\Contracts\HasAttachments;
use Modules\Document\Domain\Models\Attachment;

/**
 * Withdraws a file, for example a blurry photo taken by mistake. It is only marked deleted: the row
 * and the file stay, so evidence is never silently destroyed and the audit log keeps the trail.
 */
final class DeleteAttachment
{
    public function handle(Attachment $attachment, User $user): void
    {
        $attachable = $attachment->attachable;

        // Only the person who sent it, and only while they can still add to that visit.
        if (! $attachable instanceof HasAttachments || $attachment->uploaded_by !== $user->id || ! $attachable->canAttachFiles($user)) {
            throw new AuthorizationException('Lampiran ini tidak dapat dihapus.');
        }

        $attachment->delete();
    }
}
