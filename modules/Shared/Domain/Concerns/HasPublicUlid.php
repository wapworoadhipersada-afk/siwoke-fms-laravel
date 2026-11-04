<?php

declare(strict_types=1);

namespace Modules\Shared\Domain\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Gives a model a `ulid` column that is safe to expose in URLs and APIs,
 * so internal sequential ids never leave the system.
 */
trait HasPublicUlid
{
    public static function bootHasPublicUlid(): void
    {
        static::creating(static function (Model $model): void {
            if ($model->getAttribute('ulid') === null) {
                $model->setAttribute('ulid', (string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
