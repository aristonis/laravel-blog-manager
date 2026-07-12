<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Tests;

/**
 * Migrates the whole schema with `author_key_type => 'ulid'`.
 */
class UlidAuthorKeyTestCase extends AuthorKeyDriverTestCase
{
    protected function authorKeyType(): string
    {
        return 'ulid';
    }
}
