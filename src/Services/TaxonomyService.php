<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Services;

use Aristonis\BlogManager\Authorization\Abilities;
use Aristonis\BlogManager\Authorization\ServiceAuthorizer;
use Aristonis\BlogManager\Events\CategoryCreated;
use Aristonis\BlogManager\Events\CategoryDeleted;
use Aristonis\BlogManager\Events\CategoryUpdated;
use Aristonis\BlogManager\Events\PostCategorized;
use Aristonis\BlogManager\Events\PostTagged;
use Aristonis\BlogManager\Events\TagCreated;
use Aristonis\BlogManager\Events\TagDeleted;
use Aristonis\BlogManager\Events\TagUpdated;
use Aristonis\BlogManager\Exceptions\CategoryNotFoundException;
use Aristonis\BlogManager\Exceptions\InvalidTaxonomyDataException;
use Aristonis\BlogManager\Exceptions\TagNotFoundException;
use Aristonis\BlogManager\Models\Category;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\Tag;
use Aristonis\BlogManager\Support\SlugGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Taxonomy term lifecycle for both axes (categories + tags). Owns transactions
 * and dispatches domain events after commit. Categories are curated (unique
 * names); tags are free-form (names may repeat). Slugs are table-unique in each
 * axis and stable across renames — mirroring Post slug behavior via the shared
 * SlugGenerator. The package ships no listeners (D12).
 */
final class TaxonomyService
{
    public function __construct(
        private readonly ServiceAuthorizer $guard,
        private readonly SlugGenerator $slugs,
    ) {}

    /**
     * Create a curated category. Rejects an empty name and a duplicate name
     * (categories are unique); derives a table-unique slug. CategoryCreated
     * fires after commit. Guarded by blog.taxonomy.manage.
     */
    public function createCategory(string $name, ?string $slug = null): Category
    {
        $this->guard->ensure(Abilities::TAXONOMY_MANAGE);
        $name = $this->requireName($name);
        $this->requireUniqueCategoryName($name);
        $base = $this->baseSlug($slug, $name);

        try {
            return DB::transaction(function () use ($name, $base): Category {
                $category = Category::create([
                    'name' => $name,
                    'slug' => $this->slugs->unique(Category::class, $base, fallback: 'category'),
                ]);

                event(new CategoryCreated($category));

                return $category;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Concurrency backstop: the pre-check (requireUniqueCategoryName)
            // passed, but a concurrent writer committed the same name before our
            // INSERT and the DB unique(name) constraint fired. Translate the raw
            // QueryException subclass into the same domain error the pre-check
            // raises so a lost race never surfaces as a bare QueryException (L3).
            // Mirrors BlockService::append's unique-violation translation.
            throw new InvalidTaxonomyDataException(
                "A category named [{$name}] already exists.",
                ['field' => 'name', 'name' => $name],
                $e,
            );
        }
    }

    /**
     * Create a free-form tag. Rejects an empty name; tag names may repeat, so
     * only the slug is uniquified. TagCreated fires after commit. Guarded by
     * blog.taxonomy.manage — direct term management. (Auto-create during attach
     * rides on the post's blog.post.update guard instead; see resolveTag.)
     */
    public function createTag(string $name, ?string $slug = null): Tag
    {
        $this->guard->ensure(Abilities::TAXONOMY_MANAGE);

        return $this->persistTag($name, $slug);
    }

    /**
     * Persist a tag (no authorization). Used by the guarded createTag and by the
     * attach path's auto-create, which is already authorized as a post edit
     * (blog.post.update) — so minting a tag by name while tagging a post does
     * not additionally require blog.taxonomy.manage (O-4; tags are free-form).
     */
    private function persistTag(string $name, ?string $slug = null): Tag
    {
        $name = $this->requireName($name);
        $base = $this->baseSlug($slug, $name);

        return DB::transaction(function () use ($name, $base): Tag {
            $tag = Tag::create([
                'name' => $name,
                'slug' => $this->slugs->unique(Tag::class, $base, fallback: 'tag'),
            ]);

            event(new TagCreated($tag));

            return $tag;
        });
    }

    /**
     * Rename a category. The slug is stable — changed only when a new one is
     * supplied (then re-uniquified, ignoring this row). Renaming to another
     * category's name is rejected (unique names). CategoryUpdated fires after
     * commit. Guarded by blog.taxonomy.manage.
     */
    public function renameCategory(Category $category, string $name, ?string $slug = null): Category
    {
        $this->guard->ensure(Abilities::TAXONOMY_MANAGE);
        $name = $this->requireName($name);
        $this->requireUniqueCategoryName($name, $category->id);

        return DB::transaction(function () use ($category, $name, $slug): Category {
            $category->name = $name;

            if ($slug !== null) {
                $category->slug = $this->slugs->unique(Category::class, Str::slug($slug), $category->id, 'category');
            }

            $category->save();

            event(new CategoryUpdated($category));

            return $category->refresh();
        });
    }

    /**
     * Rename a tag. Tag names may repeat, so no uniqueness check; the slug is
     * stable unless a new one is supplied. TagUpdated fires after commit.
     * Guarded by blog.taxonomy.manage.
     */
    public function renameTag(Tag $tag, string $name, ?string $slug = null): Tag
    {
        $this->guard->ensure(Abilities::TAXONOMY_MANAGE);
        $name = $this->requireName($name);

        return DB::transaction(function () use ($tag, $name, $slug): Tag {
            $tag->name = $name;

            if ($slug !== null) {
                $tag->slug = $this->slugs->unique(Tag::class, Str::slug($slug), $tag->id, 'tag');
            }

            $tag->save();

            event(new TagUpdated($tag));

            return $tag->refresh();
        });
    }

    /**
     * Delete a category, first detaching every post pivot row so posts survive
     * (a taxonomy op never deletes content). CategoryDeleted fires after commit.
     * Guarded by blog.taxonomy.manage.
     */
    public function deleteCategory(Category $category): void
    {
        $this->guard->ensure(Abilities::TAXONOMY_MANAGE);

        DB::transaction(function () use ($category): void {
            $category->posts()->detach();
            $category->delete();

            event(new CategoryDeleted($category));
        });
    }

    /**
     * Delete a tag, first detaching every post pivot row so posts survive.
     * TagDeleted fires after commit. Guarded by blog.taxonomy.manage.
     */
    public function deleteTag(Tag $tag): void
    {
        $this->guard->ensure(Abilities::TAXONOMY_MANAGE);

        DB::transaction(function () use ($tag): void {
            $tag->posts()->detach();
            $tag->delete();

            event(new TagDeleted($tag));
        });
    }

    // ---- attach / detach: a post's terms (§2.2, FR-52/53/54) ---------------

    /**
     * Attach categories to a post (idempotent; duplicate pivots are ignored).
     * Pass $sync = true (or call syncCategories) to replace the post's category
     * set instead. Every category must pre-exist and is given as a Category
     * model or its public-id string; an unknown id throws
     * CategoryNotFoundException. Runs in one transaction; PostCategorized
     * (added + removed) fires after commit. Guarded by blog.post.update.
     *
     * @param  iterable<Category|string>  $categories
     */
    public function categorize(Post $post, iterable $categories, bool $sync = false): void
    {
        $this->guard->ensure(Abilities::POST_UPDATE, $post);
        $this->writeCategories($post, $categories, $sync);
    }

    /**
     * Replace the post's entire category set with the given categories,
     * detaching any not listed. See {@see categorize()} for accepted input.
     *
     * @param  iterable<Category|string>  $categories
     */
    public function syncCategories(Post $post, iterable $categories): void
    {
        $this->guard->ensure(Abilities::POST_UPDATE, $post);
        $this->writeCategories($post, $categories, true);
    }

    /**
     * Detach the given categories from the post (idempotent — detaching an
     * unattached category is a no-op). PostCategorized fires after commit with
     * the removed set. Guarded by blog.post.update.
     *
     * @param  iterable<Category|string>  $categories
     */
    public function uncategorize(Post $post, iterable $categories): void
    {
        $this->guard->ensure(Abilities::POST_UPDATE, $post);

        DB::transaction(function () use ($post, $categories): void {
            $ids = $this->resolveCategoryIds($categories);
            $removed = $this->attachedCategoryKeys($post, $ids);
            $post->categories()->detach($ids);

            // A detach that removes nothing is a no-op (M2): stay silent so a host
            // listener never fires on a non-change.
            if ($removed === []) {
                return;
            }

            event(new PostCategorized($post, [], $this->categoriesByKey($removed)));
        });
    }

    /**
     * Attach tags to a post (idempotent). Pass $sync = true (or call syncTags)
     * to replace the post's tag set instead. A tag is given as a Tag model, a
     * public-id string, or a NAME string: a ULID-shaped string resolves an
     * existing tag by public id (unknown id → TagNotFoundException); any other
     * string is a name, resolved to an existing tag or, when
     * taxonomy.tags.auto_create is on (default), find-or-created via
     * {@see createTag()} so slug + TagCreated stay consistent (else
     * TagNotFoundException). Runs in one transaction; PostTagged (added +
     * removed) fires after commit. Guarded by blog.post.update.
     *
     * @param  iterable<Tag|string>  $tags
     */
    public function tag(Post $post, iterable $tags, bool $sync = false): void
    {
        $this->guard->ensure(Abilities::POST_UPDATE, $post);
        $this->writeTags($post, $tags, $sync);
    }

    /**
     * Replace the post's entire tag set with the given tags, detaching any not
     * listed. See {@see self::tag()} for accepted input.
     *
     * @param  iterable<Tag|string>  $tags
     */
    public function syncTags(Post $post, iterable $tags): void
    {
        $this->guard->ensure(Abilities::POST_UPDATE, $post);
        $this->writeTags($post, $tags, true);
    }

    /**
     * Detach the given tags from the post (idempotent). PostTagged fires after
     * commit with the removed set. Guarded by blog.post.update.
     *
     * @param  iterable<Tag|string>  $tags
     */
    public function untag(Post $post, iterable $tags): void
    {
        $this->guard->ensure(Abilities::POST_UPDATE, $post);

        DB::transaction(function () use ($post, $tags): void {
            $ids = $this->resolveTagIds($tags);
            $removed = $this->attachedTagKeys($post, $ids);
            $post->tags()->detach($ids);

            // A detach that removes nothing is a no-op (M2): stay silent so a host
            // listener never fires on a non-change.
            if ($removed === []) {
                return;
            }

            event(new PostTagged($post, [], $this->tagsByKey($removed)));
        });
    }

    // ---- reads: direct membership only (§2.6, FR-55/56/57) -----------------
    // All reads are UNGUARDED — no authz on reads (§2.6), consistent with
    // PostService::find / paginate.

    /**
     * The posts directly filed under a category (direct membership only — no
     * descendant rollup), newest-first, paginated. Honors the published-
     * visibility filter (Post::scopePublished) when $onlyPublished. A single
     * indexed pivot join (NFR-24).
     *
     * @return LengthAwarePaginator<int, Post>
     */
    public function postsByCategory(Category $category, int $perPage = 15, bool $onlyPublished = false): LengthAwarePaginator
    {
        return $this->paginateAttachedPosts($category->posts(), $perPage, $onlyPublished);
    }

    /**
     * The posts directly tagged with a tag (direct membership only), newest-
     * first, paginated. See {@see postsByCategory()}.
     *
     * @return LengthAwarePaginator<int, Post>
     */
    public function postsByTag(Tag $tag, int $perPage = 15, bool $onlyPublished = false): LengthAwarePaginator
    {
        return $this->paginateAttachedPosts($tag->posts(), $perPage, $onlyPublished);
    }

    /**
     * All categories, flat and ordered by name for stable output.
     *
     * @return Collection<int, Category>
     */
    public function categories(): Collection
    {
        return Category::query()->orderBy('name')->get();
    }

    /**
     * All tags, flat and ordered by name for stable output.
     *
     * @return Collection<int, Tag>
     */
    public function tags(): Collection
    {
        return Tag::query()->orderBy('name')->get();
    }

    /**
     * A post's terms on both axes, eager-loaded to avoid N+1.
     *
     * @return array{categories: Collection<int, Category>, tags: Collection<int, Tag>}
     */
    public function for(Post $post): array
    {
        $post->loadMissing(['categories', 'tags']);

        return [
            'categories' => $post->categories,
            'tags' => $post->tags,
        ];
    }

    /**
     * Resolve one category by its public id OR slug (grouped clause so neither
     * arm leaks across the other — mirrors PostService::find). An unknown term
     * throws CategoryNotFoundException.
     */
    public function getCategory(string $idOrSlug): Category
    {
        $category = Category::query()
            ->where(fn (Builder $query) => $query
                ->where('public_id', $idOrSlug)
                ->orWhere('slug', $idOrSlug))
            ->first();

        if ($category === null) {
            throw new CategoryNotFoundException("Category [{$idOrSlug}] was not found.", ['id' => $idOrSlug]);
        }

        return $category;
    }

    /**
     * Resolve one tag by its public id OR slug (grouped clause). An unknown term
     * throws TagNotFoundException.
     */
    public function getTag(string $idOrSlug): Tag
    {
        $tag = Tag::query()
            ->where(fn (Builder $query) => $query
                ->where('public_id', $idOrSlug)
                ->orWhere('slug', $idOrSlug))
            ->first();

        if ($tag === null) {
            throw new TagNotFoundException("Tag [{$idOrSlug}] was not found.", ['id' => $idOrSlug]);
        }

        return $tag;
    }

    /**
     * Paginate the posts directly attached through a term pivot: a single
     * indexed join, newest-first, honoring Post::scopePublished when requested.
     * Ordering: publish recency for the published-only branch (drafts carry a null
     * published_at), creation recency otherwise. The published branch adds an id
     * tiebreaker so posts sharing a published_at can't skip/duplicate across page
     * boundaries (non-unique sort key). Columns are qualified so the pivot join
     * stays unambiguous.
     *
     * @param  BelongsToMany<Post, Category>|BelongsToMany<Post, Tag>  $posts
     * @return LengthAwarePaginator<int, Post>
     */
    private function paginateAttachedPosts(BelongsToMany $posts, int $perPage, bool $onlyPublished): LengthAwarePaginator
    {
        $post = $posts->getRelated();

        $query = $onlyPublished
            ? $posts->published()
                ->orderByDesc($post->qualifyColumn('published_at'))
                ->orderByDesc($post->getQualifiedKeyName())
            : $posts->orderByDesc($post->getQualifiedKeyName());

        // The pivot carries no payload (§2.2); drop the pivot intersection the
        // relation paginator infers so callers see plain Post models.
        /** @var LengthAwarePaginator<int, Post> $page */
        $page = $query->paginate($perPage);

        return $page;
    }

    /**
     * Attach or replace the post's categories in one transaction, dispatching
     * the PostCategorized delta the pivot sync reports.
     *
     * @param  iterable<Category|string>  $categories
     */
    private function writeCategories(Post $post, iterable $categories, bool $replace): void
    {
        DB::transaction(function () use ($post, $categories, $replace): void {
            $ids = $this->resolveCategoryIds($categories);
            $changes = $replace
                ? $post->categories()->sync($ids)
                : $post->categories()->syncWithoutDetaching($ids);

            // Dispatch only on a real delta (M2): an all-empty sync result (nothing
            // attached, nothing detached) must stay silent so a host listener
            // (webhooks, cache invalidation) never fires on a phantom no-op.
            if ($changes['attached'] === [] && $changes['detached'] === []) {
                return;
            }

            event(new PostCategorized(
                $post,
                $this->categoriesByKey($changes['attached']),
                $this->categoriesByKey($changes['detached']),
            ));
        });
    }

    /**
     * Attach or replace the post's tags in one transaction (auto-created tags
     * roll back with the pivot on failure), dispatching the PostTagged delta.
     *
     * @param  iterable<Tag|string>  $tags
     */
    private function writeTags(Post $post, iterable $tags, bool $replace): void
    {
        DB::transaction(function () use ($post, $tags, $replace): void {
            $ids = $this->resolveTagIds($tags);
            $changes = $replace
                ? $post->tags()->sync($ids)
                : $post->tags()->syncWithoutDetaching($ids);

            // Dispatch only on a real delta (M2): an all-empty sync result must
            // stay silent so a host listener never fires on a phantom no-op.
            if ($changes['attached'] === [] && $changes['detached'] === []) {
                return;
            }

            event(new PostTagged(
                $post,
                $this->tagsByKey($changes['attached']),
                $this->tagsByKey($changes['detached']),
            ));
        });
    }

    /**
     * Resolve category inputs to a de-duplicated list of primary keys.
     *
     * @param  iterable<Category|string>  $categories
     * @return list<int>
     */
    private function resolveCategoryIds(iterable $categories): array
    {
        $ids = [];

        foreach ($categories as $category) {
            $ids[] = $this->resolveCategory($category)->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Resolve tag inputs (model / public id / name) to a de-duplicated list of
     * primary keys, auto-creating tags by name where configured.
     *
     * @param  iterable<Tag|string>  $tags
     * @return list<int>
     */
    private function resolveTagIds(iterable $tags): array
    {
        $ids = [];

        foreach ($tags as $tag) {
            $ids[] = $this->resolveTag($tag)->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * A category is either a model or its opaque public id — it must pre-exist
     * (a taxonomy op never invents a category). An unknown id fails loud.
     */
    private function resolveCategory(Category|string $category): Category
    {
        if ($category instanceof Category) {
            return $category;
        }

        $found = Category::query()->where('public_id', trim($category))->first();

        if ($found === null) {
            throw new CategoryNotFoundException("Category [{$category}] was not found.", ['id' => $category]);
        }

        return $found;
    }

    /**
     * A tag is a model, a public id, or a name. Public ids are opaque ULIDs, so
     * a ULID-shaped string resolves an existing tag by public id (unknown →
     * fail loud); any other string is a name, resolved to an existing tag or
     * find-or-created when auto_create is on (else fail loud). Auto-create uses
     * the unguarded persistTag — the caller already cleared blog.post.update.
     */
    private function resolveTag(Tag|string $tag): Tag
    {
        if ($tag instanceof Tag) {
            return $tag;
        }

        $value = trim($tag);

        if (Str::isUlid($value)) {
            $found = Tag::query()->where('public_id', $value)->first();

            if ($found === null) {
                throw new TagNotFoundException("Tag [{$value}] was not found.", ['id' => $value]);
            }

            return $found;
        }

        $existing = Tag::query()->where('name', $value)->first();

        if ($existing !== null) {
            return $existing;
        }

        if (! (bool) config('blog-manager.taxonomy.tags.auto_create', true)) {
            throw new TagNotFoundException("Tag [{$value}] was not found.", ['name' => $value]);
        }

        return $this->persistTag($value);
    }

    /**
     * The subset of $ids currently attached as categories, read at the query
     * level so a detach event reports only the rows it actually removes.
     *
     * @param  list<int>  $ids
     * @return list<mixed>
     */
    private function attachedCategoryKeys(Post $post, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $categories = $post->categories();

        return array_values($categories->whereKey($ids)->pluck($categories->getRelated()->getQualifiedKeyName())->all());
    }

    /**
     * The subset of $ids currently attached as tags (query-level, see
     * {@see attachedCategoryKeys()}).
     *
     * @param  list<int>  $ids
     * @return list<mixed>
     */
    private function attachedTagKeys(Post $post, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $tags = $post->tags();

        return array_values($tags->whereKey($ids)->pluck($tags->getRelated()->getQualifiedKeyName())->all());
    }

    /**
     * Load categories by primary key for a delta-event payload (no query for an
     * empty key set).
     *
     * @param  array<array-key, mixed>  $keys
     * @return list<Category>
     */
    private function categoriesByKey(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return array_values(Category::query()->whereIn('id', $keys)->get()->all());
    }

    /**
     * Load tags by primary key for a delta-event payload.
     *
     * @param  array<array-key, mixed>  $keys
     * @return list<Tag>
     */
    private function tagsByKey(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return array_values(Tag::query()->whereIn('id', $keys)->get()->all());
    }

    /** Trim and require a non-empty term name (fail-loud). */
    private function requireName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw new InvalidTaxonomyDataException(
                'A taxonomy term requires a non-empty name.',
                ['field' => 'name'],
            );
        }

        return $trimmed;
    }

    /**
     * Reject a category name already used by another category (unique names).
     * $ignoreId excludes the row being renamed so a no-op rename is allowed.
     */
    private function requireUniqueCategoryName(string $name, ?int $ignoreId = null): void
    {
        $exists = Category::query()
            ->where('name', $name)
            ->when($ignoreId !== null, fn (Builder $query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw new InvalidTaxonomyDataException(
                "A category named [{$name}] already exists.",
                ['field' => 'name', 'name' => $name],
            );
        }
    }

    private function baseSlug(?string $slug, string $name): string
    {
        return is_string($slug) && $slug !== '' ? Str::slug($slug) : Str::slug($name);
    }
}
