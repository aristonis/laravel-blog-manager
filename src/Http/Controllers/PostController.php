<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Http\Controllers;

use Aristonis\BlogManager\Authorization\Abilities;
use Aristonis\BlogManager\BlogManager;
use Aristonis\BlogManager\Contracts\Authorizer;
use Aristonis\BlogManager\Exceptions\InvalidPostDataException;
use Aristonis\BlogManager\Exceptions\PostNotFoundException;
use Aristonis\BlogManager\Http\Resources\PostResource;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Services\PostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/** Thin JSON adapter over PostService. Validation + errors come from the service. */
final class PostController
{
    public function __construct(
        private readonly PostService $posts,
        private readonly BlogManager $manager,
        private readonly Authorizer $authorizer,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PostResource::collection(
            $this->posts->paginate(onlyPublished: ! $this->canSeeDrafts($request)),
        );
    }

    public function store(Request $request): PostResource
    {
        return new PostResource($this->posts->create($request->all()));
    }

    public function show(Request $request, Post $post): JsonResponse
    {
        // Scope before authorize: a hidden post is a 404 to a caller who lacks
        // the update ability, never a 403 that would confirm it exists.
        if (! $post->isVisible() && ! $this->canSeeDrafts($request, $post)) {
            throw new PostNotFoundException(
                "Post [{$post->public_id}] was not found.",
                ['id' => $post->public_id],
            );
        }

        $post->load('blocks.mediaItem');

        return response()->json(['data' => [
            'id' => $post->public_id,
            'title' => $post->title,
            'slug' => $post->slug,
            'author_id' => $post->author_id,
            'status' => $post->status->value,
            'published_at' => $post->published_at,
            'blocks' => $this->manager->render($post),
        ]]);
    }

    public function publish(Request $request, Post $post): PostResource
    {
        return new PostResource($this->posts->publish($post, $this->parsePublishedAt($request->input('published_at'))));
    }

    /**
     * Parse an optional published_at from raw request input. Invalid input is a
     * catchable package error (422), never an unhandled 500.
     */
    private function parsePublishedAt(mixed $at): ?Carbon
    {
        if (! is_string($at) || $at === '') {
            return null;
        }

        try {
            return Carbon::parse($at);
        } catch (\InvalidArgumentException) {
            // Carbon\Exceptions\InvalidFormatException extends \InvalidArgumentException.
            throw new InvalidPostDataException(
                'The published_at value must be a valid date.',
                ['field' => 'published_at'],
            );
        }
    }

    public function unpublish(Post $post): PostResource
    {
        return new PostResource($this->posts->unpublish($post));
    }

    public function update(Request $request, Post $post): PostResource
    {
        return new PostResource($this->posts->update($post, $request->all()));
    }

    public function destroy(Post $post): Response
    {
        $this->posts->delete($post);

        return response()->noContent();
    }

    /**
     * Whether the caller may see drafts/scheduled posts — i.e. holds the update
     * ability. With the default `none` driver everyone does; a host that wants
     * published-only public reads configures a restricting authorizer.
     */
    private function canSeeDrafts(Request $request, ?Post $post = null): bool
    {
        return $this->authorizer->allows($request->user(), Abilities::POST_UPDATE, $post);
    }
}
