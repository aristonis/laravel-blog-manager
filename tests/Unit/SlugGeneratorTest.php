<?php

declare(strict_types=1);

use Aristonis\BlogManager\Exceptions\SlugExhaustedException;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Support\SlugGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

function slugs(): SlugGenerator
{
    return app(SlugGenerator::class);
}

/**
 * A model stub whose every slug lookup reports "taken". It is the only
 * deterministic way to drive SlugGenerator past the sequential cap into the
 * random-suffix fallback AND exhaust MAX_RANDOM_ATTEMPTS: a real ULID suffix
 * carries 128 bits of entropy and cannot be pre-seeded, so a live model can
 * never be forced to collide on every random candidate. `unique()` only calls
 * `$modelClass::query()` and treats the result as a builder (where→when→exists),
 * so a plain stub with those three methods is a faithful, DB-free double.
 */
final class AlwaysTakenSlugModel
{
    public static function query(): object
    {
        return new class
        {
            public function where(mixed ...$args): static
            {
                return $this;
            }

            public function when(mixed ...$args): static
            {
                return $this;
            }

            public function exists(): bool
            {
                return true;
            }
        };
    }
}

it('returns the base slug when the table is free', function () {
    expect(slugs()->unique(Post::class, 'hello'))->toBe('hello');
});

it('falls back when the base is empty', function () {
    expect(slugs()->unique(Post::class, '', fallback: 'post'))->toBe('post');
});

it('appends a deterministic suffix on collision, walking past taken suffixes', function () {
    Post::create(['title' => 'Hello', 'slug' => 'hello']);
    expect(slugs()->unique(Post::class, 'hello'))->toBe('hello-2');

    Post::create(['title' => 'Hello 2', 'slug' => 'hello-2']);
    expect(slugs()->unique(Post::class, 'hello'))->toBe('hello-3');
});

it('ignores a given row so a rename keeps its own slug', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    // Without the ignore the same slug collides and suffixes; ignoring the row
    // itself keeps the slug stable (the rename semantics PostService relies on).
    expect(slugs()->unique(Post::class, 'hello'))->toBe('hello-2')
        ->and(slugs()->unique(Post::class, 'hello', $post->id))->toBe('hello');
});

it('throws SlugExhaustedException (never a raw QueryException) with {model, base, ignoreId} context when the random-suffix budget exhausts', function () {
    // AC-65 — force taken() true for every candidate so the sequential walk
    // exhausts the cap, the random-suffix fallback exhausts MAX_RANDOM_ATTEMPTS,
    // and the FR-86 guard fires. The typed domain exception must escape — never
    // the raw QueryException a naive fail-through would surface.
    try {
        slugs()->unique(AlwaysTakenSlugModel::class, 'busy', 7);
        test()->fail('Expected SlugExhaustedException to be thrown.');
    } catch (SlugExhaustedException $e) {
        expect($e)->not->toBeInstanceOf(QueryException::class)
            ->and($e->context())->toBe([
                'model' => AlwaysTakenSlugModel::class,
                'base' => 'busy',
                'ignoreId' => 7,
            ]);
    }
});

it('leaves the non-exhausted sequential path byte-identical', function () {
    // AC-66 — a normal unique() call (no exhaustion) still returns the exact
    // sequential slug it always did; the FR-86 guard adds nothing on this path.
    Post::create(['title' => 'Hello', 'slug' => 'hello']);

    expect(slugs()->unique(Post::class, 'hello'))->toBe('hello-2');
});

it('caps the sequential suffix walk and falls back to a random suffix so it always terminates', function () {
    // Seed the base plus hello-2..hello-100 so the deterministic -2/-3/… walk
    // exhausts the cap. (100 mirrors SlugGenerator::MAX_SEQUENTIAL_SUFFIX.)
    $rows = [['title' => 'Hello', 'slug' => 'hello', 'public_id' => (string) Str::ulid()]];
    for ($n = 2; $n <= 100; $n++) {
        $rows[] = ['title' => "Hello {$n}", 'slug' => "hello-{$n}", 'public_id' => (string) Str::ulid()];
    }
    Post::query()->insert($rows);

    $slug = slugs()->unique(Post::class, 'hello');

    // Past the cap it must NOT keep walking numerically (that would be 'hello-101');
    // it falls back to a random/ULID suffix that is unique and free.
    expect($slug)->not->toMatch('/^hello-\d+$/')
        ->and($slug)->toStartWith('hello-')
        ->and(Post::query()->where('slug', $slug)->exists())->toBeFalse();
});
