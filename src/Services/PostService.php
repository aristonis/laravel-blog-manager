<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Services;

use Aristonis\BlogManager\Authorization\Abilities;
use Aristonis\BlogManager\Authorization\ServiceAuthorizer;
use Aristonis\BlogManager\Enums\PostStatus;
use Aristonis\BlogManager\Events\PostCreated;
use Aristonis\BlogManager\Events\PostDeleted;
use Aristonis\BlogManager\Events\PostPublished;
use Aristonis\BlogManager\Events\PostUnpublished;
use Aristonis\BlogManager\Events\PostUpdated;
use Aristonis\BlogManager\Exceptions\InvalidPostDataException;
use Aristonis\BlogManager\Exceptions\PostNotFoundException;
use Aristonis\BlogManager\Models\Post;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Post lifecycle. Owns transactions; dispatches domain events after commit.
 */
final class PostService
{
    public function __construct(
        private readonly ServiceAuthorizer $guard,
        private readonly RevisionService $revisions,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Post
    {
        $this->guard->ensure(Abilities::POST_CREATE);
        $title = $this->requireTitle($attributes['title'] ?? null);
        $slug = $this->uniqueSlug($this->baseSlug($attributes, $title));

        return DB::transaction(function () use ($title, $slug, $attributes): Post {
            $post = Post::create([
                'title' => $title,
                'slug' => $slug,
                'author_id' => $attributes['author_id'] ?? null,
                'status' => PostStatus::Draft,
            ]);

            event(new PostCreated($post));

            return $post;
        });
    }

    /**
     * Find a post by its public id or slug, with blocks + media eager-loaded.
     * When $onlyPublished is true, a draft/scheduled post is treated as absent
     * (scope-before-authorize: callers surface a 404, never a 403).
     */
    public function find(string $idOrSlug, bool $onlyPublished = false): Post
    {
        $post = Post::query()
            ->with('blocks.mediaItem')
            ->when($onlyPublished, fn ($query) => $query->published())
            ->where(fn ($query) => $query
                ->where('public_id', $idOrSlug)
                ->orWhere('slug', $idOrSlug))
            ->first();

        if ($post === null) {
            throw new PostNotFoundException("Post [{$idOrSlug}] was not found.", ['id' => $idOrSlug]);
        }

        return $post;
    }

    /**
     * @return LengthAwarePaginator<int, Post>
     */
    public function paginate(int $perPage = 15, bool $onlyPublished = false): LengthAwarePaginator
    {
        $query = $onlyPublished
            ? Post::query()->published()->orderByDesc('published_at')
            : Post::query()->orderByDesc('id');

        return $query->paginate($perPage);
    }

    /**
     * Publish a post. A future $at schedules it (Published, but not yet visible
     * until published_at passes — see PostStatus). Dispatches PostPublished
     * after commit.
     */
    public function publish(Post $post, ?DateTimeInterface $at = null): Post
    {
        $this->guard->ensure(Abilities::POST_UPDATE, $post);

        return DB::transaction(function () use ($post, $at): Post {
            $post->update([
                'status' => PostStatus::Published,
                'published_at' => $at ?? now(),
            ]);

            // Auto-capture what went live (D26/O-1). Manual snapshots stay
            // available; a host can opt out via revisions.snapshot_on_publish.
            if ((bool) config('blog-manager.revisions.snapshot_on_publish', true)) {
                $this->revisions->snapshot($post, 'published');
            }

            event(new PostPublished($post));

            return $post->refresh();
        });
    }

    /** Return a post to draft (clears published_at). Dispatches PostUnpublished after commit. */
    public function unpublish(Post $post): Post
    {
        $this->guard->ensure(Abilities::POST_UPDATE, $post);

        return DB::transaction(function () use ($post): Post {
            $post->update([
                'status' => PostStatus::Draft,
                'published_at' => null,
            ]);

            event(new PostUnpublished($post));

            return $post->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Post $post, array $attributes): Post
    {
        $this->guard->ensure(Abilities::POST_UPDATE, $post);
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
        $this->guard->ensure(Abilities::POST_DELETE, $post);

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
