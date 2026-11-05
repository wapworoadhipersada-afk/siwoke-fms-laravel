<?php

declare(strict_types=1);

namespace Modules\Master\Application\Contracts;

use Illuminate\Validation\ValidationException;
use Modules\Master\Domain\Models\Terminal;

/**
 * A rule that must hold before a TID may be deleted (no assets installed, no open ticket, ...). Modules that
 * know about such things register their own by tagging an implementation `master.terminal_deletion_guards`,
 * so master data never depends on them.
 */
interface TerminalDeletionGuard
{
    /**
     * @throws ValidationException when the TID must not be deleted yet
     */
    public function assertCanDelete(Terminal $terminal): void;
}
