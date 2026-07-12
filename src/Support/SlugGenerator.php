<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Support;

use Aristonis\BlogManager\Exceptions\SlugExhaustedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * Bounded retry budget for {@see retryOnCollision()}. Mirrors
     * BlockService::MAX_APPEND_RETRIES: after this many consecutive lost slug
     * races (each a committed same-slug winner appearing between the unique()
     * check and the insert), the helper stops retrying and fails loud.
     */
    public const MAX_COLLISION_RETRIES = 3;

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

        // FR-86 — every random candidate collided (astronomically unlikely for a
        // 128-bit ULID suffix). Fail loud with a typed domain exception rather
        // than silently returning a still-taken slug that would trip a raw
        // unique-constraint QueryException at the insert site.
        if ($this->taken($modelClass, $candidate, $ignoreId)) {
            throw new SlugExhaustedException(
                'Could not mint a unique slug for ['.$modelClass.'] from base ['.$slug
                .'] after exhausting the random-suffix budget.',
                ['model' => $modelClass, 'base' => $slug, 'ignoreId' => $ignoreId],
            );
        }

        return $candidate;
    }

    /**
     * Run a slug-writing operation, retrying on a lost slug race. $operation is
     * self-contained: it re-derives a fresh slug via unique() and inserts/saves
     * the row (typically the whole DB::transaction(...) closure). On a
     * UniqueConstraintViolationException — a concurrent writer committed the
     * same slug between our unique() check and our insert — the committed winner
     * is now visible to unique(), so re-running the operation picks a fresh
     * suffix. Only UniqueConstraintViolationException is retried: an FK /
     * NOT-NULL / deadlock QueryException propagates unretried and unmislabelled
     * (mirrors BlockService::append). After $tries exhausted collisions it fails
     * loud with SlugExhaustedException. The happy path returns on the first
     * successful attempt, adding zero overhead.
     *
     * It retries on ANY UniqueConstraintViolationException raised inside the
     * closure and, on exhaustion, surfaces SlugExhaustedException — so a
     * NON-slug unique violation inside a wrapped operation (e.g. a
     * cross-post-copied block public_id in RevisionService::restore, or a
     * name-race in renameCategory) is retried and relabelled as slug
     * exhaustion. This is a deliberate simplification; a caller that needs
     * constraint-precise handling must not route through this helper.
     *
     * @internal Not part of the supported public API — hosts must not depend on
     * it. The package's services use it internally; its behavior may change
     * without a major-version bump.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @param  array<string, mixed>  $context
     * @return T
     */
    public function retryOnCollision(callable $operation, array $context = [], int $tries = self::MAX_COLLISION_RETRIES): mixed
    {
        $last = null;

        for ($attempt = 0; $attempt < $tries; $attempt++) {
            try {
                return $operation();
            } catch (UniqueConstraintViolationException $e) {
                $last = $e;
            }
        }

        throw new SlugExhaustedException(
            'Could not mint a unique slug after '.$tries.' collision retries.',
            $context,
            $last,
        );
    }

    /**
     * Whether $candidate is already used in $modelClass's `slug` column,
     * excluding $ignoreId so a row never collides with itself.
     *
     * Impure by nature: it reads live table state, so a concurrent writer can
     * flip the answer between two calls with identical arguments. The
     * annotation stops static analysis from folding the FR-86 exhaustion
     * re-check into an "always true" branch.
     *
     * @param  class-string<Model>  $modelClass
     *
     * @phpstan-impure
     */
    private function taken(string $modelClass, string $candidate, ?int $ignoreId): bool
    {
        return $modelClass::query()
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn (Builder $query) => $query->where('id', '!=', $ignoreId))
            ->exists();
    }
}
