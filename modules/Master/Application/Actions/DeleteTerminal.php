<?php

declare(strict_types=1);

namespace Modules\Master\Application\Actions;

use Illuminate\Contracts\Container\Container;
use Modules\Master\Application\Contracts\TerminalDeletionGuard;
use Modules\Master\Domain\Models\Terminal;

/**
 * Soft-deletes a TID, unless a registered guard objects (assets still installed on it, tickets still open).
 */
final class DeleteTerminal
{
    public function __construct(private readonly Container $container) {}

    public function handle(Terminal $terminal): void
    {
        foreach ($this->container->tagged('master.terminal_deletion_guards') as $guard) {
            if ($guard instanceof TerminalDeletionGuard) {
                $guard->assertCanDelete($terminal);
            }
        }

        $terminal->delete();
    }
}
