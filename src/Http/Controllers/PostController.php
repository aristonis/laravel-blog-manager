<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Http\Controllers;

use Aristonis\BlogManager\BlogManager;
use Aristonis\BlogManager\Http\Resources\PostResource;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Services\PostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Thin JSON adapter over PostService. Validation + errors come from the service. */
final class PostController
{
    public function __construct(
        private readonly PostService $posts,
        private readonly BlogManager $manager,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return PostResource::collection($this->posts->paginate());
    }

    public function store(Request $request): PostResource
    {
        return new PostResource($this->posts->create($request->all()));
    }

    public function show(Post $post): JsonResponse
    {
        $post->load('blocks.mediaItem');

        return response()->json(['data' => [
            'id' => $post->public_id,
            'title' => $post->title,
            'slug' => $post->slug,
            'author_id' => $post->author_id,
            'blocks' => $this->manager->render($post),
        ]]);
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
}
