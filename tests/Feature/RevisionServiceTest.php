<?php

declare(strict_types=1);

use Aristonis\BlogManager\Enums\PostStatus;
use Aristonis\BlogManager\Events\PostRevisionCreated;
use Aristonis\BlogManager\Exceptions\RevisionMediaMissingException;
use Aristonis\BlogManager\Exceptions\RevisionNotFoundException;
use Aristonis\BlogManager\Media\MediaManager;
use Aristonis\BlogManager\Models\MediaItem;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\PostRevision;
use Aristonis\BlogManager\Services\BlockService;
use Aristonis\BlogManager\Services\PostService;
use Aristonis\BlogManager\Services\RevisionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

function revs(): RevisionService
{
    return app(RevisionService::class);
}

function seededPost(): Post
{
    Storage::fake('public');
    $posts = app(PostService::class);
    $blocks = app(BlockService::class);
    $media = app(MediaManager::class)->store(UploadedFile::fake()->image('a.png'));

    $post = $posts->create(['title' => 'Original', 'slug' => 'original']);
    $blocks->append($post, 'heading', ['text' => 'Title', 'level' => 1]);
    $blocks->append($post, 'paragraph', ['format' => 'markdown', 'content' => '**hi**']);
    $blocks->append($post, 'image', ['alt' => 'pic'], $media);

    return $post->fresh();
}

it('captures a full snapshot of the post and block tree and fires PostRevisionCreated', function () {
    Event::fake([PostRevisionCreated::class]);
    $post = seededPost();

    $revision = revs()->snapshot($post, 'manual', 42);
    $snapshot = $revision->fresh()->snapshot;

    expect($snapshot['post']['title'])->toBe('Original')
        ->and($snapshot['post']['slug'])->toBe('original')
        ->and($snapshot['post']['status'])->toBe('draft')
        ->and($snapshot['blocks'])->toHaveCount(3)
        ->and($snapshot['blocks'][0]['type'])->toBe('heading')
        ->and($snapshot['blocks'][0]['data'])->toMatchArray(['text' => 'Title'])
        ->and($snapshot['blocks'][2]['type'])->toBe('image')
        ->and($snapshot['blocks'][2]['media']['original_filename'])->toBe('a.png')
        ->and($revision->label)->toBe('manual')
        ->and($revision->created_by)->toEqual(42);

    Event::assertDispatched(PostRevisionCreated::class);
});

it('lists revisions newest-first and fetches one by public id, scoped to the post', function () {
    $post = seededPost();
    $first = revs()->snapshot($post, 'one');
    $second = revs()->snapshot($post, 'two');

    expect(revs()->for($post)->pluck('public_id')->all())->toBe([$second->public_id, $first->public_id])
        ->and(revs()->get($post, $first->public_id)->is($first))->toBeTrue();

    $other = Post::create(['title' => 'Other', 'slug' => 'other']);
    $foreign = revs()->snapshot($other);

    expect(fn () => revs()->get($post, $foreign->public_id))->toThrow(RevisionNotFoundException::class);
    expect(fn () => revs()->get($post, 'does-not-exist'))->toThrow(RevisionNotFoundException::class);
});

it('restores content non-destructively and records a new revision', function () {
    $post = seededPost();
    $original = revs()->snapshot($post);

    app(PostService::class)->update($post, ['title' => 'Changed']);
    $post->blocks()->delete();
    $before = $post->fresh()->revisions()->count();

    revs()->restore($post->fresh(), $original->fresh());
    $fresh = $post->fresh();

    expect($fresh->title)->toBe('Original')
        ->and($fresh->blocks->pluck('type')->all())->toBe(['heading', 'paragraph', 'image'])
        ->and($fresh->revisions()->count())->toBeGreaterThan($before); // append-only

    // the pre-restore state (title 'Changed', no blocks) is retained
    $preRestore = $fresh->revisions->firstWhere('label', 'auto: before restore');
    expect($preRestore->snapshot['post']['title'])->toBe('Changed')
        ->and($preRestore->snapshot['blocks'])->toHaveCount(0);
});

it('leaves publish state untouched by default and restores it on request', function () {
    $post = seededPost();
    $draftRevision = revs()->snapshot($post);

    app(PostService::class)->publish($post);
    expect($post->fresh()->status)->toBe(PostStatus::Published);

    // content-only restore: the post stays published
    revs()->restore($post->fresh(), $draftRevision->fresh());
    expect($post->fresh()->status)->toBe(PostStatus::Published);

    // with the flag, the draft publish state comes back
    revs()->restore($post->fresh(), $draftRevision->fresh(), restorePublishState: true);
    expect($post->fresh()->status)->toBe(PostStatus::Draft)
        ->and($post->fresh()->published_at)->toBeNull();
});

it('restores a non-null published_at when restorePublishState is set', function () {
    Carbon::setTestNow('2026-07-03 10:00:00');
    $post = seededPost();
    app(PostService::class)->publish($post);
    $publishedRevision = $post->fresh()->revisions->firstWhere('label', 'published');

    app(PostService::class)->unpublish($post);
    expect($post->fresh()->status)->toBe(PostStatus::Draft);

    revs()->restore($post->fresh(), $publishedRevision, restorePublishState: true);
    $fresh = $post->fresh();

    expect($fresh->status)->toBe(PostStatus::Published)
        ->and($fresh->published_at)->not->toBeNull();

    Carbon::setTestNow();
});

it('does not evict the source revision when restoring under a tight retention cap', function () {
    config()->set('blog-manager.revisions.keep', 1);
    $post = seededPost();
    $source = revs()->snapshot($post, 'source');
    expect($post->fresh()->revisions)->toHaveCount(1); // keep=1 pruned to just this

    app(PostService::class)->update($post, ['title' => 'Changed']);
    revs()->restore($post->fresh(), $source->fresh());

    // the source survived the restore (record() does not prune) and content is back
    expect($post->fresh()->title)->toBe('Original')
        ->and(PostRevision::query()->where('public_id', $source->public_id)->exists())->toBeTrue();
});

it('handles missing media on restore: strict throws, lenient drops, remap repairs', function () {
    $post = seededPost();
    $revision = revs()->snapshot($post);
    $media = MediaItem::query()->first();

    // free the media from the live block, then hard-delete it — the revision
    // still references it, so restore must handle the gap.
    $post->blocks()->where('type', 'image')->update(['media_item_id' => null]);
    app(MediaManager::class)->delete($media->fresh());

    config()->set('blog-manager.revisions.on_missing_media', 'strict');
    expect(fn () => revs()->restore($post->fresh(), $revision->fresh()))
        ->toThrow(RevisionMediaMissingException::class);

    config()->set('blog-manager.revisions.on_missing_media', 'lenient');
    revs()->restore($post->fresh(), $revision->fresh());
    expect($post->fresh()->blocks->pluck('type')->all())->toBe(['heading', 'paragraph']);

    // re-upload and repair via a remap — the image block comes back on the new media
    Storage::fake('public');
    $replacement = app(MediaManager::class)->store(UploadedFile::fake()->image('b.png'));
    config()->set('blog-manager.revisions.on_missing_media', 'strict');
    revs()->restore($post->fresh(), $revision->fresh(), mediaRemap: [$media->public_id => $replacement->public_id]);

    $fresh = $post->fresh();
    expect($fresh->blocks->pluck('type')->all())->toBe(['heading', 'paragraph', 'image'])
        ->and($fresh->blocks->firstWhere('type', 'image')->media_item_id)->toBe($replacement->id);
});

it('prunes to revisions.keep, keeping the newest', function () {
    config()->set('blog-manager.revisions.keep', 2);
    $post = seededPost();

    revs()->snapshot($post, 'v1');
    revs()->snapshot($post, 'v2');
    revs()->snapshot($post, 'v3');

    expect($post->fresh()->revisions->pluck('label')->all())->toBe(['v3', 'v2']);

    config()->set('blog-manager.revisions.keep', null);
    revs()->snapshot($post, 'v4');
    expect($post->fresh()->revisions)->toHaveCount(3);
});

it('rolls the whole restore back on failure (atomic)', function () {
    $post = seededPost();
    $revision = revs()->snapshot($post);
    app(PostService::class)->update($post, ['title' => 'Changed']);

    try {
        DB::transaction(function () use ($post, $revision): void {
            revs()->restore($post->fresh(), $revision->fresh());
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // swallow — asserting the restore was rolled back with the outer transaction
    }

    expect($post->fresh()->title)->toBe('Changed'); // restore undone
});
