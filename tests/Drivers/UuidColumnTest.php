<?php

declare(strict_types=1);

use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Tests\UuidAuthorKeyTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SG-5 (FR-91) — uuid native-column proof, driver-only (AC-78).
 *
 * The whole schema is migrated under `author_key_type => 'uuid'` by
 * {@see UuidAuthorKeyTestCase} (config pinned in defineEnvironment, before the
 * migrations run). On SQLite the column collapses to `varchar` affinity, which
 * cannot prove the native type — so this auto-skips on SQLite and runs only on
 * the PG/MySQL CI legs. Postgres reports the native `uuid` type; MySQL reports a
 * fixed `char(36)`. CI-ONLY: verified on GitHub Actions' `databases` job.
 */
uses(UuidAuthorKeyTestCase::class);

beforeEach(function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('requires a real DB driver (mysql/pgsql)');
    }
});

it('emits a native uuid column for blog_posts.author_id (AC-78)', function () {
    $column = collect(Schema::getColumns('blog_posts'))->firstWhere('name', 'author_id');
    $type = strtolower((string) ($column['type'] ?? ''));
    $typeName = strtolower((string) ($column['type_name'] ?? ''));

    if (DB::connection()->getDriverName() === 'pgsql') {
        // Native Postgres uuid — not a string affinity.
        expect($typeName)->toBe('uuid');
    } else {
        // MySQL has no native uuid; the driver stores a fixed char(36), never varchar.
        expect($type)->toContain('char(36)')
            ->and($type)->not->toContain('varchar');
    }
});

it('round-trips a 36-char uuid author id without coercion (AC-78)', function () {
    $author = (string) Str::uuid();
    expect($author)->toHaveLength(36);

    $post = Post::create(['title' => 'uuid host', 'slug' => 'uuid-host', 'author_id' => $author]);

    expect($post->fresh()->author_id)->toBe($author);
});
