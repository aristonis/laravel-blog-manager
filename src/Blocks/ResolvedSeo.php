<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Blocks;

/**
 * Immutable, presentation-ready SEO meta-bag: the resolved page/title/robots/og
 * values a host serializes into `<head>`. Flat, typed props for PHP ergonomics;
 * a symmetric nested `toArray()` (both `robots` AND `og` grouped) for host
 * serialization. Carries no Eloquent and no markup — the resolver applies every
 * fallback, this DTO just holds the outcome. This shape is the pinned 1.0 host
 * contract (design §3.3); the page `title` is never overridden by `ogTitle`.
 */
final class ResolvedSeo
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $canonicalUrl,
        public readonly bool $noindex,
        public readonly bool $nofollow,
        public readonly string $ogTitle,
        public readonly ?string $ogDescription,
        public readonly ?string $ogImage,
        public readonly string $ogType,
    ) {}

    /**
     * The frozen symmetric-nested serialization: scalar top-level fields plus the
     * grouped `robots` and `og` bags.
     *
     * @return array{
     *     title: string,
     *     description: ?string,
     *     canonicalUrl: ?string,
     *     robots: array{noindex: bool, nofollow: bool},
     *     og: array{title: string, description: ?string, image: ?string, type: string},
     * }
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'canonicalUrl' => $this->canonicalUrl,
            'robots' => [
                'noindex' => $this->noindex,
                'nofollow' => $this->nofollow,
            ],
            'og' => [
                'title' => $this->ogTitle,
                'description' => $this->ogDescription,
                'image' => $this->ogImage,
                'type' => $this->ogType,
            ],
        ];
    }
}
