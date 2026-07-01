<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Services;

use Aristonis\BlogManager\Events\PostCreated;
use Aristonis\BlogManager\Events\PostDeleted;
use Aristonis\BlogManager\Events\PostUpdated;
use Aristonis\BlogManager\Exceptions\InvalidPostDataException;
use Aristonis\BlogManager\Exceptions\PostNotFoundException;
use Aristonis\BlogManager\Models\Post;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Post lifecycle. Owns transactions; dispatches domain events after commit.
 */
final class PostService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Post
    {
        $title = $this->requireTitle($attributes['title'] ?? null);
        $slug = $this->uniqueSlug($this->baseSlug($attributes, $title));

        return DB::transaction(function () use ($title, $slug, $attributes): Post {
            $post = Post::create([
                'title' => $title,
                'slug' => $slug,
                'author_id' => $attributes['author_id'] ?? null,
            ]);

            event(new PostCreated($post));

            return $post;
        });
    }

    /** Find a post by its public id or slug, with blocks + media eager-loaded. */
    public function find(string $idOrSlug): Post
    {
        $post = Post::query()
            ->with('blocks.mediaItem')
            ->where('public_id', $idOrSlug)
            ->orWhere('slug', $idOrSlug)
            ->first();

        if ($post === null) {
            throw new PostNotFoundException("Post [{$idOrSlug}] was not found.", ['id' => $idOrSlug]);
        }

        return $post;
    }

    /**
     * @return LengthAwarePaginator<int, Post>
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Post::query()->orderByDesc('id')->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Post $post, array $attributes): Post
    {
        $changes = [];

        if (array_key_exists('title', $attributes)) {
            $changes['title'] = $this->requireTitle($attributes['title']);
        }
        if (array_key_exists('slug', $attributes)) {
            $changes['slug'] = $this->uniqueSlug(Str::slug((string) $attributes['slug']), $post->id);
        }
        if (array_key_exists('author_id', $attributes)) {
            $changes['author_id'] = $attributes['author_id'];
        }

        return DB::transaction(function () use ($post, $changes): Post {
            $post->update($changes);

            event(new PostUpdated($post));

            return $post->refresh();
        });
    }

    public function delete(Post $post): void
    {
        DB::transaction(function () use ($post): void {
            $post->blocks()->delete();
            $post->delete();

            event(new PostDeleted($post));
        });
    }

    private function requireTitle(mixed $title): string
    {
        if (! is_string($title) || trim($title) === '') {
            throw new InvalidPostDataException('A post requires a non-empty title.', ['field' => 'title']);
        }

        return $title;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function baseSlug(array $attributes, string $title): string
    {
        $slug = $attributes['slug'] ?? null;

        return is_string($slug) && $slug !== '' ? Str::slug($slug) : Str::slug($title);
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = $base === '' ? 'post' : $base;
        $candidate = $slug;
        $suffix = 2;

        while (Post::query()
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
