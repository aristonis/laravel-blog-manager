<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Models;

use Aristonis\BlogManager\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable point-in-time snapshot of a post: its attributes plus the whole
 * ordered block tree (media referenced by id, not copied). Written once by the
 * RevisionService and never mutated — history is append-only. Persistence only.
 *
 * @property int $id
 * @property string $public_id
 * @property int $post_id
 * @property array<string, mixed> $snapshot
 * @property string|null $label
 * @property int|string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PostRevision extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $fillable = ['public_id', 'post_id', 'snapshot', 'label', 'created_by'];

    public function getTable(): string
    {
        $table = config('blog-manager.tables.post_revisions', 'blog_post_revisions');

        return is_string($table) ? $table : 'blog_post_revisions';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
