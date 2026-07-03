<?php

declare(strict_types=1);

use Aristonis\BlogManager\BlogManager;
use Aristonis\BlogManager\Media\MediaManager;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Services\BlockService;
use Aristonis\BlogManager\Services\PostService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Build a draft post carrying exactly $imageBlocks image blocks, each backed by
 * its own stored MediaItem. Uniquely named (renderTest*) so it never collides
 * with the suite's shared global helpers (e.g. posts()/blocks() in ServicesTest).
 */
function renderTestPostWithImageBlocks(int $imageBlocks): Post
{
    /** @var PostService $posts */
    $posts = app(PostService::class);
    /** @var BlockService $blocks */
    $blocks = app(BlockService::class);
    /** @var MediaManager $media */
    $media = app(MediaManager::class);

    $post = $posts->create(['title' => "Render N+1 Post {$imageBlocks}"]);

    for ($i = 0; $i < $imageBlocks; $i++) {
        $item = $media->store(UploadedFile::fake()->image("render-{$imageBlocks}-{$i}.png"));
        $blocks->append($post, 'image', ['alt' => "alt-{$i}"], $item);
    }

    return $post;
}

/**
 * Re-fetch a post by id WITHOUT eager-loading (mirroring a paginate()/host read,
 * NOT PostService::find()), render it through the container, and report both the
 * number of queries the render issued and its output.
 *
 * The re-fetch happens before the query log is enabled, so only the render's own
 * queries are counted.
 *
 * @return array{queries: int, output: list<array<string, mixed>>}
 */
function renderTestMeasureRender(int $postId): array
{
    /** @var Post $fresh */
    $fresh = Post::query()->whereKey($postId)->first();

    expect($fresh->relationLoaded('blocks'))->toBeFalse(); // guard: not pre-loaded

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $output = app(BlogManager::class)->render($fresh);
    } finally {
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
    }

    return ['queries' => count($queries), 'output' => $output];
}

it('renders with a bounded, constant query count regardless of media block count', function () {
    Storage::fake('public');

    $twoMedia = renderTestPostWithImageBlocks(2);
    $fourMedia = renderTestPostWithImageBlocks(4);

    $small = renderTestMeasureRender($twoMedia->id);
    $large = renderTestMeasureRender($fourMedia->id);

    // The eager-load cost is fixed: blocks (1 query) + a single batched
    // mediaItem load (1 query) = 2, never one-query-per-media-block. Before the
    // loadMissing() guard this fails RED because the count scales with media
    // blocks (3 for two-media vs 5 for four-media).
    expect($small['queries'])->toBe($large['queries'])
        ->and($large['queries'])->toBeLessThanOrEqual(2);
});

it('preserves render output — ordered blocks with populated payloads', function () {
    Storage::fake('public');

    $post = renderTestPostWithImageBlocks(3);

    $rendered = renderTestMeasureRender($post->id)['output'];

    expect($rendered)->toHaveCount(3)
        ->and(array_column($rendered, 'position'))->toBe([0, 1, 2])
        ->and(array_column($rendered, 'type'))->toBe(['image', 'image', 'image']);

    foreach ($rendered as $index => $block) {
        expect($block['payload']['alt'])->toBe("alt-{$index}")
            ->and($block['payload']['url'])->toBeString();
    }
});
