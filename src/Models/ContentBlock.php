<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Models;

use Aristonis\BlogManager\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One positioned, typed unit of a post's body. The block sequence (ordered by
 * `position`) is the article. Media blocks reference a media item by FK; text
 * blocks keep their payload in `data`.
 *
 * @property int $id
 * @property string $public_id
 * @property int $post_id
 * @property string $type
 * @property int $position
 * @property array<string, mixed>|null $data
 * @property int|null $media_item_id
 */
final class ContentBlock extends Model
{
    use HasPublicId;

    // Structural fields (post_id/type/media_item_id) and public_id are set by
    // BlockService/RevisionService via forceCreate — never mass-assigned (H3).
    /** @var list<string> */
    protected $fillable = ['position', 'data'];

    public function getTable(): string
    {
        $table = config('blog-manager.tables.content_blocks', 'blog_content_blocks');

        return is_string($table) ? $table : 'blog_content_blocks';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<MediaItem, $this>
     */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
