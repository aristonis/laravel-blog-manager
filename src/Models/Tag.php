<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Models;

use Aristonis\BlogManager\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A free-form, flat taxonomy term. Lightweight labels attached to posts;
 * names may repeat (folksonomy) while the slug stays table-unique. Persistence
 * only — all transactions and business rules live in the TaxonomyService.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Tag extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $fillable = ['name', 'slug'];

    public function getTable(): string
    {
        $table = config('blog-manager.tables.tags', 'blog_tags');

        return is_string($table) ? $table : 'blog_tags';
    }

    /**
     * The posts directly tagged with this tag (many-to-many via the configurable
     * pivot, resolved at call time). Direct membership only.
     *
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        $pivot = config('blog-manager.tables.post_tag', 'blog_post_tag');

        return $this->belongsToMany(Post::class, is_string($pivot) ? $pivot : 'blog_post_tag');
    }
}
