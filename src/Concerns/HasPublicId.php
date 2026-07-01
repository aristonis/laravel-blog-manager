<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a model an opaque, non-enumerable public identifier (`public_id`, a ULID)
 * that is distinct from its internal auto-increment primary key. The numeric key
 * is used only for relations and is never exposed; `public_id` is the route key.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model): void {
            if (empty($model->getAttribute('public_id'))) {
                $model->setAttribute('public_id', (string) Str::ulid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
