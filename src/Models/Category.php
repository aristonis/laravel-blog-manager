<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Models;

use Aristonis\BlogManager\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A curated, flat taxonomy term. A post is filed under categories the host
 * creates up front; names are unique within the table (enforced in the service)
 * and the slug is the human-friendly secondary address. Persistence only — all
 * transactions and business rules live in the TaxonomyService.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Category extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $fillable = ['name', 'slug'];

    public function getTable(): string
    {
        $table = config('blog-manager.tables.categories', 'blog_categories');

        return is_string($table) ? $table : 'blog_categories';
    }

    /**
     * The posts directly filed under this category (many-to-many via the
     * configurable pivot, resolved at call time). Direct membership only.
     *
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        $pivot = config('blog-manager.tables.post_category', 'blog_post_category');

        return $this->belongsToMany(Post::class, is_string($pivot) ? $pivot : 'blog_post_category');
    }
}
