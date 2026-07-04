<?php

declare(strict_types=1);

use Aristonis\BlogManager\Support\AuthorKeyType;

/**
 * SG-1 (H4) — the author-key resolver. A pure static helper that maps the
 * `blog-manager.author_key_type` config to a validated key and, at migrate
 * time, emits the matching column. This suite pins the resolver contract:
 * every allowed value resolves, the default is `bigint`, and a bad value
 * fails loud with the exact message shared by both fail-loud sites (O-1).
 */
it('exposes the allowed key set in declaration order', function () {
    expect(AuthorKeyType::ALLOWED)->toBe(['bigint', 'uuid', 'ulid']);
});

it('resolves each allowed key when configured', function (string $type) {
    config()->set('blog-manager.author_key_type', $type);

    expect(AuthorKeyType::resolve())->toBe($type);
})->with(['bigint', 'uuid', 'ulid']);

it('defaults to bigint when the config key is absent', function () {
    // Remove the key entirely so resolve() must fall back to its own default,
    // mirroring a host that never published/edited the config value.
    $config = config('blog-manager');
    unset($config['author_key_type']);
    config()->set('blog-manager', $config);

    expect(AuthorKeyType::resolve())->toBe('bigint');
});

it('throws InvalidArgumentException with the exact message on an unknown value', function () {
    config()->set('blog-manager.author_key_type', 'int');

    AuthorKeyType::resolve();
})->throws(
    InvalidArgumentException::class,
    'Invalid blog-manager.author_key_type [int]; allowed: bigint, uuid, ulid.',
);
