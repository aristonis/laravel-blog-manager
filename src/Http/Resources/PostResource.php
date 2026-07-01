<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Http\Resources;

use Aristonis\BlogManager\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Post
 */
final class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Post $post */
        $post = $this->resource;

        return [
            'id' => $post->public_id,
            'title' => $post->title,
            'slug' => $post->slug,
            'author_id' => $post->author_id,
            'created_at' => $post->created_at,
            'updated_at' => $post->updated_at,
        ];
    }
}
