<?php

declare(strict_types=1);

namespace Modules\Shared;

use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\Models\Activity;

final class SharedServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->stampAuditEntriesWithRequestContext();
    }

    /**
     * Every audit entry, including the ones models write on their own, records where the request came from.
     */
    private function stampAuditEntriesWithRequestContext(): void
    {
        // Registered fresh on each boot so the callback is never stacked up more than once.
        LogActivityAction::clearBeforeLoggingCallbacks();

        LogActivityAction::beforeLogging(static function (ActivityContract $activity): void {
            if (! $activity instanceof Activity) {
                return;
            }

            /** @var Collection<string, mixed> $properties */
            $properties = $activity->properties ?? new Collection;

            $activity->properties = $properties->merge([
                'ip' => $properties->get('ip', request()->ip()),
                'user_agent' => $properties->get('user_agent', request()->userAgent()),
            ]);
        });
    }
}
