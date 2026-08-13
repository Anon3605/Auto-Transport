<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Every externally addressable row carries a ULID. The auto-increment id stays
 * server-side: sequential ids leak volume and invite enumeration, while ULIDs
 * are still time-sortable so they index nearly as tightly as an integer.
 *
 * Route binding therefore resolves on `ulid` -- but an unguessable key is not
 * authorization. Every endpoint still runs its policy check.
 */
trait HasUlid
{
    protected static function bootHasUlid(): void
    {
        static::creating(function (Model $model): void {
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
