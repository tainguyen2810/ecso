<?php

namespace App\Shared\Concerns;

use Illuminate\Support\Str;

/**
 * Adds a public ULID next to the internal BIGINT key (ADR-04).
 * Route model binding and API resources only ever expose the ULID.
 */
trait HasPublicUlid
{
    public static function bootHasPublicUlid(): void
    {
        static::creating(function ($model) {
            if (empty($model->ulid)) {
                $model->ulid = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
