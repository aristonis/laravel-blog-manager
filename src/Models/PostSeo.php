<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-post SEO metadata — a 1:1 satellite of {@see Post}. Persistence only; all
 * writes/resolution live in SeoService. No public_id: this record is never
 * independently addressable, always reached through its post (like the pivots).
 *
 * @property int $id
 * @property int $post_id
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string|null $canonical_url
 * @property bool $noindex
 * @property bool $nofollow
 * @property string|null $og_title
 * @property string|null $og_description
 * @property string|null $og_image
 * @property string|null $og_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PostSeo extends Model
{
    // post_id is structural — set by the hasOne relation, never mass-assigned (H3).
    /** @var list<string> */
    protected $fillable = [
        'meta_title',
        'meta_description',
        'canonical_url',
        'noindex',
        'nofollow',
        'og_title',
        'og_description',
        'og_image',
        'og_type',
    ];

    public function getTable(): string
    {
        $table = config('blog-manager.tables.post_seo', 'blog_post_seo');

        return is_string($table) ? $table : 'blog_post_seo';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'noindex' => 'boolean',
            'nofollow' => 'boolean',
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
