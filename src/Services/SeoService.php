<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Services;

use Aristonis\BlogManager\Authorization\Abilities;
use Aristonis\BlogManager\Authorization\ServiceAuthorizer;
use Aristonis\BlogManager\Events\PostSeoUpdated;
use Aristonis\BlogManager\Exceptions\InvalidSeoDataException;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\PostSeo;
use Aristonis\BlogManager\Seo\ResolvedSeo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per-post SEO metadata writes (the read/resolve path lands in SG-3). Writes are
 * guard-first (blog.post.update), trim + validate every override fail-loud (no
 * silent truncation), then upsert in one transaction with an after-commit
 * PostSeoUpdated event. `set` is a FULL replace (an omitted field resets to its
 * default); `update` is partial (only provided keys change). Reads are unguarded.
 */
final class SeoService
{
    /** Max length of meta_title / og_title (mirrors the column width). */
    public const META_TITLE_MAX = 255;

    /** Max length of meta_description / og_description. */
    public const META_DESCRIPTION_MAX = 500;

    /** Max length of any URL override (canonical_url / og_image). */
    public const URL_MAX = 2048;

    /** Max length of og_type. */
    public const OG_TYPE_MAX = 64;

    /** Config fallback for the resolved og:type when neither stored nor configured. */
    private const DEFAULT_OG_TYPE = 'article';

    /** Config fallback for the excerpt character budget when unset/invalid. */
    private const DEFAULT_EXCERPT_LENGTH = 155;

    /**
     * The string overrides and their fail-loud length caps. Every string field
     * is capped so nothing is ever silently truncated (foresight MED-3).
     *
     * @var array<string, int>
     */
    private const STRING_CAPS = [
        'meta_title' => self::META_TITLE_MAX,
        'meta_description' => self::META_DESCRIPTION_MAX,
        'canonical_url' => self::URL_MAX,
        'og_title' => self::META_TITLE_MAX,
        'og_description' => self::META_DESCRIPTION_MAX,
        'og_image' => self::URL_MAX,
        'og_type' => self::OG_TYPE_MAX,
    ];

    /** @var list<string> */
    private const BOOL_FIELDS = ['noindex', 'nofollow'];

    public function __construct(
        private readonly ServiceAuthorizer $guard,
    ) {}

    /**
     * Full upsert: every fillable field the caller omits resets to its default
     * (all string overrides to null, noindex/nofollow to false), so a re-set that
     * drops a field actually clears it (AC-52). Guarded blog.post.update
     * FIRST, then trim+validate, then one transaction; PostSeoUpdated after commit.
     *
     * @param  array<string, mixed>  $data
     */
    public function set(Post $post, array $data): PostSeo
    {
        $this->guard->ensure(Abilities::POST_UPDATE, $post);

        $attributes = array_merge($this->defaults(), $this->validate($data));

        return $this->persist($post, $attributes);
    }

    /**
     * Partial update: only the provided keys change; every other stored field is
     * left untouched. Guarded blog.post.update FIRST, then trim+validate the
     * provided keys, then one transaction; PostSeoUpdated after commit.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Post $post, array $data): PostSeo
    {
        $this->guard->ensure(Abilities::POST_UPDATE, $post);

        return $this->persist($post, $this->validate($data));
    }

    /**
     * The post's raw stored SEO record, or null when unset. UNGUARDED (a read).
     * Queries the relation directly so the result always reflects DB state, never
     * a stale cached relation on the passed instance.
     */
    public function for(Post $post): ?PostSeo
    {
        return $post->seo()->first();
    }

    /**
     * The post's resolved SEO meta-bag with every fallback applied (design §3.4).
     * UNGUARDED, pure: no writes, deterministic, idempotent (NFR-26). The page
     * `title` is the meta title or the post title — `og_title` never overrides it
     * (D-c). `loadMissing('seo')` reuses an eager-loaded relation (feed perf,
     * NFR-28); the block tree is touched only via `firstParagraph` and only when a
     * description must be derived — never the whole tree.
     */
    public function resolve(Post $post): ResolvedSeo
    {
        $seo = $this->loadedSeoFor($post);

        // No stored row → resolve purely from the post (title + derived excerpt);
        // every override is at its default. Split so the has-row branch reads plain
        // (non-nullsafe) attributes over a guaranteed-present record.
        if ($seo === null) {
            $title = $post->title;
            $description = $this->excerpt($post);

            return new ResolvedSeo(
                title: $title,
                description: $description,
                canonicalUrl: null,
                noindex: false,
                nofollow: false,
                ogTitle: $title,
                ogDescription: $description,
                ogImage: null,
                ogType: $this->defaultOgType(),
            );
        }

        // og_title/og_description never override the page title/description (D-c);
        // the excerpt is derived only when meta_description is unset (feed perf).
        $title = $seo->meta_title ?? $post->title;
        $description = $seo->meta_description ?? $this->excerpt($post);

        return new ResolvedSeo(
            title: $title,
            description: $description,
            canonicalUrl: $seo->canonical_url,
            noindex: $seo->noindex,
            nofollow: $seo->nofollow,
            ogTitle: $seo->og_title ?? $title,
            ogDescription: $seo->og_description ?? $description,
            ogImage: $seo->og_image,
            ogType: $seo->og_type ?? $this->defaultOgType(),
        );
    }

    /**
     * The post's stored SEO row via the (eager-loadable) relation, or null when
     * unset — the optional 1:1 satellite the resolver's ?? fallbacks build on.
     * Uses loadMissing so an eager-loaded feed reuses the relation (NFR-28) rather
     * than re-querying like for().
     */
    private function loadedSeoFor(Post $post): ?PostSeo
    {
        $post->loadMissing('seo');

        // Read the now-loaded relation from the relations bag without re-querying
        // (feed perf, NFR-28). The hasOne accessor is statically non-null, but a
        // post with no SEO row genuinely resolves null at runtime; the raw bag is an
        // honest mixed, so the null case is preserved for the resolver's fallbacks.
        $seo = $post->getRelations()['seo'] ?? null;

        return $seo instanceof PostSeo ? $seo : null;
    }

    /**
     * The excerpt fallback (O-2): the first paragraph block's text, markdown/HTML
     * stripped to plain text, mb-safe word-boundary truncated to the configured
     * budget. No paragraph — or an empty/whitespace-only one — yields null so the
     * host, not the package, decides how to render a description-less post. Reads
     * one block via `firstParagraph` (loadMissing reuses an eager load), never the
     * full block tree (NFR-28).
     */
    private function excerpt(Post $post): ?string
    {
        $post->loadMissing('firstParagraph');
        $paragraph = $post->firstParagraph;

        if ($paragraph === null) {
            return null;
        }

        $plain = $this->toPlainText(is_array($paragraph->data) ? $paragraph->data : []);

        if ($plain === '') {
            return null;
        }

        return Str::limit($plain, $this->excerptLength(), preserveWords: true);
    }

    /**
     * Reduce a paragraph block's stored `data` to plain, single-spaced text. A
     * markdown paragraph is rendered (raw HTML stripped) then de-tagged and
     * entity-decoded; a plain paragraph is literal text left intact (never
     * `strip_tags`ed, so a literal `<` a user typed is not swallowed). Whitespace
     * runs collapse to single spaces so multi-line bodies excerpt cleanly.
     *
     * @param  array<string, mixed>  $data
     */
    private function toPlainText(array $data): string
    {
        $content = is_string($data['content'] ?? null) ? $data['content'] : '';

        if (trim($content) === '') {
            return '';
        }

        if (($data['format'] ?? 'plain') === 'markdown') {
            $html = Str::markdown($content, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
            $content = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return trim((string) preg_replace('/\s+/u', ' ', $content));
    }

    /** The configured og:type default, coerced to a non-empty string fallback. */
    private function defaultOgType(): string
    {
        $type = config('blog-manager.seo.default_og_type', self::DEFAULT_OG_TYPE);

        return is_string($type) && $type !== '' ? $type : self::DEFAULT_OG_TYPE;
    }

    /** The configured excerpt character budget, coerced to a positive int fallback. */
    private function excerptLength(): int
    {
        $length = config('blog-manager.seo.excerpt_length', self::DEFAULT_EXCERPT_LENGTH);

        return is_int($length) && $length > 0 ? $length : self::DEFAULT_EXCERPT_LENGTH;
    }

    /**
     * Upsert the post's SEO row in one transaction and dispatch PostSeoUpdated
     * after commit. `updateOrCreate` is SELECT-then-INSERT (not atomic): two
     * concurrent first-writes both INSERT and one trips unique(post_id). Catch
     * that and retry once — the row now exists, so the upsert intent resolves to
     * an update. Mirrors TaxonomyService / BlockService's unique-violation handling.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persist(Post $post, array $attributes): PostSeo
    {
        try {
            return $this->upsert($post, $attributes);
        } catch (UniqueConstraintViolationException) {
            return $this->upsert($post, $attributes);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(Post $post, array $attributes): PostSeo
    {
        return DB::transaction(function () use ($post, $attributes): PostSeo {
            $seo = $post->seo()->updateOrCreate([], $attributes);

            event(new PostSeoUpdated($post));

            return $seo;
        });
    }

    /**
     * Validate + normalize the provided overrides: trim every string (empty /
     * whitespace-only → null), enforce the fail-loud length cap, coerce the
     * boolean flags, and reject any unknown key (strict — a typo'd field never
     * silently vanishes). Returns only the provided keys, normalized.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|bool|null>
     */
    private function validate(array $data): array
    {
        $validated = [];

        foreach ($data as $key => $value) {
            if (array_key_exists($key, self::STRING_CAPS)) {
                $validated[$key] = $this->normalizeString($key, $value, self::STRING_CAPS[$key]);
            } elseif (in_array($key, self::BOOL_FIELDS, true)) {
                $validated[$key] = (bool) $value;
            } else {
                throw new InvalidSeoDataException(
                    "Unknown SEO field [{$key}].",
                    ['field' => $key],
                );
            }
        }

        return $validated;
    }

    /**
     * Trim a string override, storing empty/whitespace-only as null; reject a
     * non-string value and any value exceeding the field cap (fail-loud, no
     * silent truncation). Uses mb_strlen so multibyte overrides are measured by
     * character, matching the column width intent.
     */
    private function normalizeString(string $field, mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidSeoDataException(
                "SEO field [{$field}] must be a string or null.",
                ['field' => $field],
            );
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > $max) {
            throw new InvalidSeoDataException(
                "SEO field [{$field}] exceeds the maximum length of {$max} characters.",
                ['field' => $field, 'max' => $max],
            );
        }

        return $trimmed;
    }

    /**
     * The full fillable set at its defaults — the baseline a `set` merges the
     * validated overrides over, so an omitted field resets rather than persisting
     * a stale value (foresight MED-1). og_type has no DB default; the resolver
     * (SG-3) owns the config-driven default, so the stored default is null.
     *
     * @return array<string, string|bool|null>
     */
    private function defaults(): array
    {
        return [
            'meta_title' => null,
            'meta_description' => null,
            'canonical_url' => null,
            'noindex' => false,
            'nofollow' => false,
            'og_title' => null,
            'og_description' => null,
            'og_image' => null,
            'og_type' => null,
        ];
    }
}
