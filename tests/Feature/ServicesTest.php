<?php

declare(strict_types=1);

use Aristonis\BlogManager\BlogManager;
use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Events\BlockUpdated;
use Aristonis\BlogManager\Events\MediaStored;
use Aristonis\BlogManager\Events\PostCreated;
use Aristonis\BlogManager\Exceptions\BlockKindMismatchException;
use Aristonis\BlogManager\Exceptions\BlockPositionOutOfRangeException;
use Aristonis\BlogManager\Exceptions\PostNotFoundException;
use Aristonis\BlogManager\Media\MediaManager;
use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\MediaItem;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Services\BlockService;
use Aristonis\BlogManager\Services\PostService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

function posts(): PostService
{
    return app(PostService::class);
}

function blocks(): BlockService
{
    return app(BlockService::class);
}

it('creates a post with a derived unique slug', function () {
    $a = posts()->create(['title' => 'My First Post']);
    $b = posts()->create(['title' => 'My First Post']);

    expect($a->slug)->toBe('my-first-post')
        ->and($b->slug)->toBe('my-first-post-2')
        ->and($a->public_id)->toHaveLength(26);
});

it('finds a post by public id or slug with ordered blocks', function () {
    $post = posts()->create(['title' => 'Hello']);
    blocks()->append($post, 'paragraph', ['format' => 'plain', 'content' => 'one']);

    expect(posts()->find($post->public_id)->slug)->toBe('hello')
        ->and(posts()->find('hello')->blocks)->toHaveCount(1);

    expect(fn () => posts()->find('nope'))->toThrow(PostNotFoundException::class);
});

it('updates and deletes a post, cascading blocks but keeping media', function () {
    Storage::fake('public');
    $post = posts()->create(['title' => 'Draft']);
    $media = app(MediaManager::class)->store(UploadedFile::fake()->image('a.png'));
    blocks()->append($post, 'image', ['alt' => 'x'], $media);

    posts()->update($post, ['title' => 'Final']);
    expect($post->fresh()->title)->toBe('Final');

    posts()->delete($post);
    expect(Post::count())->toBe(0)
        ->and(ContentBlock::count())->toBe(0)
        ->and(MediaItem::find($media->id))->not->toBeNull(); // media retained
});

it('appends blocks in authored order', function () {
    $post = posts()->create(['title' => 'P']);
    blocks()->append($post, 'heading', ['text' => 'Title', 'level' => 1]);
    blocks()->append($post, 'paragraph', ['format' => 'markdown', 'content' => '**hi**']);

    $ordered = $post->fresh()->blocks;
    expect($ordered->pluck('type')->all())->toBe(['heading', 'paragraph'])
        ->and($ordered->pluck('position')->all())->toBe([0, 1]);
});

it('updates a block payload and dispatches BlockUpdated after commit', function () {
    $fired = 0;
    Event::listen(BlockUpdated::class, function () use (&$fired) {
        $fired++;
    });

    $post = posts()->create(['title' => 'P']);
    $block = blocks()->append($post, 'paragraph', ['format' => 'plain', 'content' => 'one']);

    $updated = blocks()->update($block, ['format' => 'plain', 'content' => 'two']);

    expect($updated->data['content'])->toBe('two')
        ->and($block->fresh()->data['content'])->toBe('two')
        ->and($fired)->toBe(1);
});

it('rejects a media block whose media kind does not match', function () {
    $post = posts()->create(['title' => 'P']);
    $video = MediaItem::forceCreate([
        'kind' => MediaKind::Video, 'mime' => 'video/mp4', 'size' => 1,
        'original_filename' => 'v.mp4', 'adapter' => 'filesystem',
    ]);

    expect(fn () => blocks()->append($post, 'image', ['alt' => 'x'], $video))
        ->toThrow(BlockKindMismatchException::class);

    expect(fn () => blocks()->append($post, 'image', ['alt' => 'x'], null))
        ->toThrow(BlockKindMismatchException::class);
});

it('removes a middle block and re-sequences positions', function () {
    $post = posts()->create(['title' => 'P']);
    $b0 = blocks()->append($post, 'heading', ['text' => 'a']);
    $b1 = blocks()->append($post, 'paragraph', ['content' => 'b']);
    $b2 = blocks()->append($post, 'paragraph', ['content' => 'c']);

    blocks()->remove($b1);

    $remaining = $post->fresh()->blocks;
    expect($remaining->pluck('public_id')->all())->toBe([$b0->public_id, $b2->public_id])
        ->and($remaining->pluck('position')->all())->toBe([0, 1]);
});

it('reorders blocks and keeps positions contiguous', function () {
    $post = posts()->create(['title' => 'P']);
    $b0 = blocks()->append($post, 'heading', ['text' => 'a']);
    $b1 = blocks()->append($post, 'paragraph', ['content' => 'b']);
    $b2 = blocks()->append($post, 'paragraph', ['content' => 'c']);

    blocks()->reorder($post, [$b2->public_id, $b0->public_id, $b1->public_id]);

    $reordered = $post->fresh()->blocks;
    expect($reordered->pluck('public_id')->all())->toBe([$b2->public_id, $b0->public_id, $b1->public_id])
        ->and($reordered->pluck('position')->all())->toBe([0, 1, 2]);

    expect(fn () => blocks()->reorder($post, [$b0->public_id]))
        ->toThrow(BlockPositionOutOfRangeException::class);
});

it('renders a post as an ordered, sanitized payload list', function () {
    Storage::fake('public');
    $post = posts()->create(['title' => 'T']);
    $media = app(MediaManager::class)->store(UploadedFile::fake()->image('a.png'));
    blocks()->append($post, 'paragraph', ['format' => 'plain', 'content' => 'hi']);
    blocks()->append($post, 'image', ['alt' => 'x'], $media);

    $rendered = app(BlogManager::class)->render($post->fresh());

    expect($rendered)->toHaveCount(2)
        ->and($rendered[0]['type'])->toBe('paragraph')
        ->and($rendered[0]['payload']['html'])->toBe('<p>hi</p>')
        ->and($rendered[1]['type'])->toBe('image')
        ->and($rendered[1]['payload']['alt'])->toBe('x')
        ->and($rendered[1]['payload']['url'])->toBeString();
});

it('dispatches PostCreated after the transaction commits', function () {
    $fired = 0;
    Event::listen(PostCreated::class, function () use (&$fired) {
        $fired++;
    });

    posts()->create(['title' => 'X']);

    expect($fired)->toBe(1);
});

it('does not dispatch when the surrounding transaction rolls back', function () {
    $fired = 0;
    Event::listen(PostCreated::class, function () use (&$fired) {
        $fired++;
    });

    try {
        DB::transaction(function () {
            posts()->create(['title' => 'Y']);
            throw new RuntimeException('boom');
        });
    } catch (Throwable) {
        // expected
    }

    expect($fired)->toBe(0)
        ->and(Post::count())->toBe(0);
});

it('dispatches MediaStored when media is stored', function () {
    Storage::fake('public');
    $fired = 0;
    Event::listen(MediaStored::class, function () use (&$fired) {
        $fired++;
    });

    app(MediaManager::class)->store(UploadedFile::fake()->image('a.png'));

    expect($fired)->toBe(1);
});
