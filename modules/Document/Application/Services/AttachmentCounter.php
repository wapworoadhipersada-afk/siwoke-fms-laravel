<?php

declare(strict_types=1);

namespace Modules\Document\Application\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Document\Domain\Contracts\HasAttachments;
use Modules\Document\Domain\Enums\AttachmentKind;

/**
 * How many live files of each kind something has. Other modules use it to check that required
 * evidence is in before they let work be closed.
 */
final class AttachmentCounter
{
    /**
     * @return array<string, int> count per kind value, zero for kinds with none
     */
    public function countByKind(Model&HasAttachments $attachable): array
    {
        $counts = array_fill_keys(array_map(static fn (AttachmentKind $kind): string => $kind->value, AttachmentKind::cases()), 0);

        $rows = $attachable->attachments()->selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind');

        foreach ($rows as $kind => $total) {
            $counts[(string) $kind] = (int) $total;
        }

        return $counts;
    }
}
