<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Models;

use Aristonis\BlogManager\Concerns\HasPublicId;
use Aristonis\BlogManager\Enums\MediaKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A first-class media record. Independent of posts/blocks and reusable; the
 * actual binary is owned by the active storage adapter, which this row only
 * references (`adapter` + `disk`/`path` or provider `meta`).
 *
 * @property int $id
 * @property string $public_id
 * @property MediaKind $kind
 * @property string $mime
 * @property int $size
 * @property string $original_filename
 * @property string $adapter
 * @property string|null $disk
 * @property string|null $path
 * @property array<string, mixed>|null $meta
 */
final class MediaItem extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $fillable = [
        'public_id', 'kind', 'mime', 'size', 'original_filename', 'adapter', 'disk', 'path', 'meta',
    ];

    public function getTable(): string
    {
        $table = config('blog-manager.tables.media_items', 'blog_media_items');

        return is_string($table) ? $table : 'blog_media_items';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'size' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * @return HasMany<ContentBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(ContentBlock::class);
    }
}
