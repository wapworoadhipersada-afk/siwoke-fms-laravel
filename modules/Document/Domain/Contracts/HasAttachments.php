<?php

declare(strict_types=1);

namespace Modules\Document\Domain\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Document\Domain\Models\Attachment;

/**
 * Implemented by anything that can carry evidence files. The document module knows only this
 * contract, so it never depends on the module that owns the thing being documented.
 */
interface HasAttachments
{
    /**
     * Who may add files to it right now (for a ticket visit: its technician, while the visit is open).
     */
    public function canAttachFiles(User $user): bool;

    /**
     * Who may see or download its files.
     */
    public function canViewAttachments(User $user): bool;

    /**
     * @return MorphMany<Attachment, covariant Model>
     */
    public function attachments(): MorphMany;
}
