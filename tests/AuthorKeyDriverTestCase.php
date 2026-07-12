<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Tests;

use Illuminate\Foundation\Application;

/**
 * A TestCase that pins `blog-manager.author_key_type` BEFORE the package
 * migrations run, so the `blog_posts.author_id` / `blog_post_revisions.created_by`
 * columns are emitted with the driver-native type for that key (uuid/ulid).
 *
 * The config must be in place at migrate time (AuthorKeyType resolves it inside
 * `Schema::create()`), and Testbench runs `defineEnvironment()` before
 * `defineDatabaseMigrations()` — so setting it here is the clean Testbench seam
 * for a whole-schema migration under a non-default key, without re-running
 * migrations by hand (which would fight the child-table FKs on real drivers).
 *
 * Concrete subclasses supply the key via {@see self::authorKeyType()}. Used only
 * by the driver-only tests, which auto-skip on SQLite.
 */
abstract class AuthorKeyDriverTestCase extends TestCase
{
    /**
     * The `author_key_type` this harness migrates under — `uuid` or `ulid`.
     */
    abstract protected function authorKeyType(): string;

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Pin the key BEFORE defineDatabaseMigrations() so AuthorKeyType::apply()
        // emits the native column type when the schema is created.
        $app['config']->set('blog-manager.author_key_type', $this->authorKeyType());
    }
}
