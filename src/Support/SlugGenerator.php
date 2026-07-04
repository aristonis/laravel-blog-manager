<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
     * Ceiling on the deterministic `-2`/`-3`/… walk. Once this many slugs on the
     * same base collide (a pathological cluster), the sequential walk stops and a
     * random ULID-based suffix is used instead — so uniquification always
     * terminates rather than looping unbounded across an ever-growing table.
     */
    public const MAX_SEQUENTIAL_SUFFIX = 100;

    /**
     * Bounded retry budget for the random-suffix fallback. A ULID suffix carries
     * 128 bits of entropy, so a single attempt is unique with overwhelming
     * probability; the small budget is a belt-and-suspenders guarantee that the
     * fallback itself can never loop forever.
     */
    private const MAX_RANDOM_ATTEMPTS = 5;

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

        while ($this->taken($modelClass, $candidate, $ignoreId)) {
            if ($suffix > self::MAX_SEQUENTIAL_SUFFIX) {
                return $this->randomizedUnique($modelClass, $slug, $ignoreId);
            }

            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Fallback for a pathological collision cluster: append a random ULID-based
     * suffix (lowercased for slug aesthetics) and retry a bounded number of times
     * until the slug is free. A slug MUST always be produced, so fail-loud is not
     * appropriate here; the finite retry budget makes an infinite loop impossible
     * while a ULID collision stays astronomically unlikely.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function randomizedUnique(string $modelClass, string $slug, ?int $ignoreId): string
    {
        $candidate = $slug;

        for ($attempt = 0; $attempt < self::MAX_RANDOM_ATTEMPTS; $attempt++) {
            $candidate = $slug.'-'.Str::lower((string) Str::ulid());

            if (! $this->taken($modelClass, $candidate, $ignoreId)) {
                break;
            }
        }

        return $candidate;
    }

    /**
     * Whether $candidate is already used in $modelClass's `slug` column,
     * excluding $ignoreId so a row never collides with itself.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function taken(string $modelClass, string $candidate, ?int $ignoreId): bool
    {
        return $modelClass::query()
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn (Builder $query) => $query->where('id', '!=', $ignoreId))
            ->exists();
    }
}
