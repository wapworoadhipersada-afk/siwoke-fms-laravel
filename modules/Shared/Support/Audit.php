<?php

declare(strict_types=1);

namespace Modules\Shared\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Single entry point for writing audit entries, so every record carries the
 * same request context (who, what, when and from which IP/device).
 */
final class Audit
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(
        string $log,
        string $event,
        string $description,
        ?Model $causer = null,
        ?Model $subject = null,
        array $properties = [],
    ): void {
        $logger = activity($log)
            ->event($event)
            ->withProperties([
                ...$properties,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

        if ($causer instanceof Model) {
            $logger->causedBy($causer);
        }

        if ($subject instanceof Model) {
            $logger->performedOn($subject);
        }

        $logger->log($description);
    }
}
