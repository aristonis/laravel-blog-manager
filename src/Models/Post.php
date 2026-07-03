<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Models;

use Aristonis\BlogManager\Concerns\HasPublicId;
use Aristonis\BlogManager\Enums\PostStatus;
use Aristonis\BlogManager\Exceptions\GenericBlogManagerException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A blog post: the top-level container that owns identity, addressing and an
 * ordered sequence of content blocks. Persistence only — all transactions and
 * business rules live in the services.
 *
 * @property int $id
 * @property string $public_id
 * @property string $title
 * @property string $slug
 * @property int|string|null $author_id
 * @property PostStatus $status
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Post extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $fillable = ['public_id', 'title', 'slug', 'author_id', 'status', 'published_at'];

    public function getTable(): string
    {
        $table = config('blog-manager.tables.posts', 'blog_posts');

        return is_string($table) ? $table : 'blog_posts';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * Publicly visible posts: Published with a published_at that has passed.
     * Scheduled posts (future published_at) and drafts are excluded.
     *
     * @param  Builder<Post>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', PostStatus::Published->value)
            ->where('published_at', '<=', now());
    }

    /**
     * @param  Builder<Post>  $query
     */
    public function scopeDraft(Builder $query): void
    {
        $query->where('status', PostStatus::Draft->value);
    }

    /**
     * @return HasMany<ContentBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(ContentBlock::class)->orderBy('position');
    }

    /**
     * The post's revision history, newest first (append-only; written by the
     * RevisionService). Ordered by id so the sequence is deterministic even when
     * two snapshots share a created_at timestamp.
     *
     * @return HasMany<PostRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PostRevision::class)->orderByDesc('id');
    }

    /**
     * The post's categories (flat, curated terms), ordered by name. Many-to-many
     * via the configurable pivot resolved at call time. Direct membership only.
     *
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        $pivot = config('blog-manager.tables.post_category', 'blog_post_category');

        return $this->belongsToMany(Category::class, is_string($pivot) ? $pivot : 'blog_post_category')
            ->orderBy('name');
    }

    /**
     * The post's tags (flat, free-form terms), ordered by name. Many-to-many via
     * the configurable pivot resolved at call time. Direct membership only.
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        $pivot = config('blog-manager.tables.post_tag', 'blog_post_tag');

        return $this->belongsToMany(Tag::class, is_string($pivot) ? $pivot : 'blog_post_tag')
            ->orderBy('name');
    }

    /**
     * The optional author, resolved from the host-configured model at call time.
     * The package never imports the host User model.
     *
     * @return BelongsTo<Model, $this>
     */
    public function author(): BelongsTo
    {
        $model = config('blog-manager.author_model');

        if (! is_string($model) || $model === '') {
            throw new GenericBlogManagerException(
                'Configure blog-manager.author_model to use the post author relation.',
            );
        }

        /** @var class-string<Model> $model */
        return $this->belongsTo($model, 'author_id');
    }
}
