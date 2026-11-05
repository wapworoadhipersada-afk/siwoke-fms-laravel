<?php

declare(strict_types=1);

namespace Modules\Document\Domain\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Document\Domain\Models\Attachment;

/**
 * Gives a model its `attachments()` relation. The model also implements HasAttachments
 * and decides who may attach and view.
 */
trait InteractsWithAttachments
{
    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
