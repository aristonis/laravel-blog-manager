<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Media;

/**
 * What a storage adapter returns after persisting a binary: the adapter name and
 * enough reference to resolve/remove it later (disk + path for filesystem, or a
 * provider-specific `meta` blob).
 */
final class StoredMediaRef
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $adapter,
        public readonly ?string $disk = null,
        public readonly ?string $path = null,
        public readonly array $meta = [],
    ) {}
}
