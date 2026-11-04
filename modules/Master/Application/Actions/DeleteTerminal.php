<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Modules\Master\Domain\Models\Terminal;

/**
 * Soft-deletes a TID. Once assets are installed on terminals this is where deleting an
 * occupied terminal must be refused.
 */
final class DeleteTerminal
{
    public function handle(Terminal $terminal): void
    {
        $terminal->delete();
    }
}
