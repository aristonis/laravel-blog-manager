<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Authorization;

/**
 * The fixed set of ability keys the package checks. These are keys only — the
 * package never defines or stores the roles/permissions behind them.
 */
final class Abilities
{
    public const POST_CREATE = 'blog.post.create';

    public const POST_UPDATE = 'blog.post.update';

    public const POST_DELETE = 'blog.post.delete';

    public const BLOCK_MANAGE = 'blog.block.manage';

    public const MEDIA_UPLOAD = 'blog.media.upload';

    public const MEDIA_DELETE = 'blog.media.delete';

    public const TAXONOMY_MANAGE = 'blog.taxonomy.manage';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::POST_CREATE,
            self::POST_UPDATE,
            self::POST_DELETE,
            self::BLOCK_MANAGE,
            self::MEDIA_UPLOAD,
            self::MEDIA_DELETE,
            self::TAXONOMY_MANAGE,
        ];
    }
}
