<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Produces a table-unique slug for any model. A pure leaf collaborator: it has no
 * dependency on a service (so it introduces no cycle) and holds no state. Given a
 * target model, a base string, an optional row to ignore, and a fallback for an
 * empty base, it appends a deterministic `-2`/`-3`/… suffix until the slug is free
 * within that model's table.
 *
 * Extracted (v0.4) from the identical `uniqueSlug` that lived on PostService and
 * RevisionService, so slug uniqueness has exactly one source of truth.
 */
final class SlugGenerator
{
    /**
     * Return a slug unique within $modelClass's `slug` column. $base is used as-is
     * (assumed already `Str::slug`-normalized by the caller); an empty $base falls
     * back to $fallback. $ignoreId excludes one row so a rename can keep its own
     * slug without colliding with itself.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function unique(string $modelClass, string $base, ?int $ignoreId = null, string $fallback = 'item'): string
    {
        $slug = $base === '' ? $fallback : $base;
        $candidate = $slug;
        $suffix = 2;

        while ($modelClass::query()
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn (Builder $query) => $query->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
