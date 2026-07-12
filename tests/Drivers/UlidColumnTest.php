<?php

declare(strict_types=1);

use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Tests\UlidAuthorKeyTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SG-5 (FR-91) — ulid native-column proof, driver-only (AC-78).
 *
 * The whole schema is migrated under `author_key_type => 'ulid'` by
 * {@see UlidAuthorKeyTestCase}. A ULID column is a fixed `char(26)` on both
 * Postgres and MySQL; SQLite collapses it to `varchar` affinity (unprovable), so
 * this auto-skips on SQLite and runs only on the PG/MySQL CI legs. CI-ONLY:
 * verified on GitHub Actions' `databases` job.
 */
uses(UlidAuthorKeyTestCase::class);

beforeEach(function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('requires a real DB driver (mysql/pgsql)');
    }
});

it('emits a fixed char(26) column for blog_posts.author_id (AC-78)', function () {
    $column = collect(Schema::getColumns('blog_posts'))->firstWhere('name', 'author_id');
    $type = strtolower((string) ($column['type'] ?? ''));

    // Fixed-width char(26) — Postgres formats it as character(26), MySQL as char(26);
    // never an unbounded varchar (which is all SQLite's affinity could offer).
    expect($type)->toMatch('/char(?:acter)?\(26\)/')
        ->and($type)->not->toContain('varchar');
});

it('round-trips a 26-char ulid author id without coercion (AC-78)', function () {
    $author = (string) Str::ulid();
    expect($author)->toHaveLength(26);

    $post = Post::create(['title' => 'ulid host', 'slug' => 'ulid-host', 'author_id' => $author]);

    expect($post->fresh()->author_id)->toBe($author);
});
