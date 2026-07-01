<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Models;

use Aristonis\BlogManager\Concerns\HasPublicId;
use Aristonis\BlogManager\Exceptions\GenericBlogManagerException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 */
final class Post extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $fillable = ['public_id', 'title', 'slug', 'author_id'];

    public function getTable(): string
    {
        $table = config('blog-manager.tables.posts', 'blog_posts');

        return is_string($table) ? $table : 'blog_posts';
    }

    /**
     * @return HasMany<ContentBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(ContentBlock::class)->orderBy('position');
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
