<?php

declare(strict_types=1);

use Aristonis\BlogManager\Contracts\MediaStorageAdapter;
use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Events\MediaDeleted;
use Aristonis\BlogManager\Exceptions\MediaInUseException;
use Aristonis\BlogManager\Media\MediaAdapterManager;
use Aristonis\BlogManager\Media\MediaManager;
use Aristonis\BlogManager\Media\MediaSource;
use Aristonis\BlogManager\Media\StoredMediaRef;
use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\MediaItem;
use Aristonis\BlogManager\Models\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * SG-3 / audit finding H2 — delete-safety contract for MediaManager::delete().
 *
 * Guarantees under test:
 *  1. Rollback safety: if the DB row delete fails inside the transaction, the
 *     binary MUST survive (adapter->delete() must NOT have run) and the row is kept.
 *  2. Happy-path ordering: the binary is deleted only AFTER the row delete commits.
 *  3. In-use enforcement: a referenced media item throws and nothing is deleted.
 */
beforeEach(function () {
    Storage::fake('public');
});

/**
 * A spy MediaStorageAdapter that records whether (and when) its binary delete ran.
 * Reuses the package's existing "anonymous adapter registered via extend()" fake
 * convention (see MediaTest.php) rather than introducing a new mocking style.
 */
function sg3SpyAdapter(): MediaStorageAdapter
{
    return new class implements MediaStorageAdapter
    {
        public bool $deleteCalled = false;

        public int $deleteCount = 0;

        /** When true, the binary delete throws to simulate a post-commit driver failure. */
        public bool $throwOnDelete = false;

        /** Whether the MediaItem row still existed in the DB at binary-delete time. */
        public ?bool $rowExistedAtDeleteTime = null;

        public function name(): string
        {
            return 'spy';
        }

        public function store(MediaSource $source, MediaKind $kind): StoredMediaRef
        {
            return new StoredMediaRef('spy', 'public', 'spy/path.bin');
        }

        public function url(MediaItem $item, ?int $ttlMinutes = null): ?string
        {
            return 'http://spy/'.$item->path;
        }

        public function delete(MediaItem $item): void
        {
            $this->deleteCalled = true;
            $this->deleteCount++;
            $this->rowExistedAtDeleteTime = MediaItem::query()->whereKey($item->getKey())->exists();

            if ($this->throwOnDelete) {
                throw new RuntimeException('forced binary delete failure');
            }
        }
    };
}

/** Persist a spy-backed MediaItem (its `adapter` column routes delete() to the spy). */
function sg3MediaItem(): MediaItem
{
    return MediaItem::forceCreate([
        'kind' => MediaKind::Image,
        'mime' => 'image/png',
        'size' => 10,
        'original_filename' => 'a.png',
        'adapter' => 'spy',
        'disk' => 'public',
        'path' => 'spy/a.png',
    ]);
}

it('leaves the binary intact and keeps the row when the DB row delete fails inside the transaction', function () {
    $spy = sg3SpyAdapter();
    app(MediaAdapterManager::class)->extend('spy', fn () => $spy);

    $media = sg3MediaItem();

    // Force the DB row delete (C) to throw from INSIDE the transaction.
    Event::listen('eloquent.deleting: '.MediaItem::class, function (): void {
        throw new RuntimeException('forced row-delete failure');
    });

    expect(fn () => app(MediaManager::class)->delete($media))
        ->toThrow(RuntimeException::class);

    // Binary must survive the rollback: the adapter delete must NOT have run.
    expect($spy->deleteCalled)->toBeFalse();
    // Row must survive the rollback.
    expect(MediaItem::find($media->id))->not->toBeNull();
});

it('deletes the row and calls the adapter binary delete once after the transaction commits', function () {
    Event::fake([MediaDeleted::class]);

    $spy = sg3SpyAdapter();
    app(MediaAdapterManager::class)->extend('spy', fn () => $spy);

    $media = sg3MediaItem();
    $id = $media->id;

    app(MediaManager::class)->delete($media);

    // Row is gone.
    expect(MediaItem::find($id))->toBeNull();
    // Binary delete ran exactly once...
    expect($spy->deleteCount)->toBe(1)
        ->and($spy->deleteCalled)->toBeTrue();
    // ...and it ran AFTER the row delete committed (row already absent at that point).
    expect($spy->rowExistedAtDeleteTime)->toBeFalse();

    Event::assertDispatched(MediaDeleted::class, 1);
});

it('refuses to delete media still referenced by a block and deletes nothing', function () {
    $spy = sg3SpyAdapter();
    app(MediaAdapterManager::class)->extend('spy', fn () => $spy);

    $media = sg3MediaItem();
    $post = Post::create(['title' => 'p', 'slug' => 'p']);
    ContentBlock::forceCreate([
        'post_id' => $post->id,
        'type' => 'image',
        'position' => 0,
        'media_item_id' => $media->id,
    ]);

    expect(fn () => app(MediaManager::class)->delete($media))
        ->toThrow(MediaInUseException::class);

    // Row + binary both intact.
    expect(MediaItem::find($media->id))->not->toBeNull()
        ->and($spy->deleteCalled)->toBeFalse();
});

it('discards the pending binary delete when a host-owned outer transaction rolls back', function () {
    // The HIGH (H2 reopened via nested tx): a host wraps delete() in its OWN
    // transaction. The inner DB::transaction is then only a SAVEPOINT, so its
    // "commit" is a savepoint release — NOT the real outermost commit. Binary
    // cleanup must defer to the true outermost commit, which never happens here.
    $spy = sg3SpyAdapter();
    app(MediaAdapterManager::class)->extend('spy', fn () => $spy);

    $media = sg3MediaItem();
    $id = $media->id;

    try {
        DB::transaction(function () use ($media): void {
            app(MediaManager::class)->delete($media);

            // Host aborts AFTER delete()'s inner savepoint releases.
            throw new RuntimeException('host rollback');
        });
    } catch (RuntimeException) {
        // swallowed: the outer host transaction rolls back
    }

    // Outer rollback restores the row...
    expect(MediaItem::find($id))->not->toBeNull();
    // ...and the binary must NEVER have been touched — otherwise it is orphaned.
    expect($spy->deleteCalled)->toBeFalse();
});

it('does not surface a post-commit binary failure and still dispatches MediaDeleted once', function () {
    Event::fake([MediaDeleted::class]);

    // The record is authoritatively gone at commit; a raw driver failure while
    // removing the (now-orphaned) binary must not be presented as a total failure.
    $spy = sg3SpyAdapter();
    $spy->throwOnDelete = true;
    app(MediaAdapterManager::class)->extend('spy', fn () => $spy);

    $media = sg3MediaItem();
    $id = $media->id;

    // Must NOT throw despite the adapter blowing up post-commit.
    app(MediaManager::class)->delete($media);

    expect(MediaItem::find($id))->toBeNull()
        ->and($spy->deleteCalled)->toBeTrue();

    // The record IS deleted, so the event fires regardless of binary outcome.
    Event::assertDispatched(MediaDeleted::class, 1);
});

it('deletes the binary before dispatching MediaDeleted (binary-first ordering)', function () {
    $spy = sg3SpyAdapter();
    app(MediaAdapterManager::class)->extend('spy', fn () => $spy);

    $media = sg3MediaItem();

    // Capture whether the binary was already gone at the instant the event fired.
    $binaryDeletedWhenEventFired = null;
    Event::listen(MediaDeleted::class, function () use ($spy, &$binaryDeletedWhenEventFired): void {
        $binaryDeletedWhenEventFired = $spy->deleteCalled;
    });

    app(MediaManager::class)->delete($media);

    // The docblock promises binary-before-event; assert the ordering holds.
    expect($binaryDeletedWhenEventFired)->toBeTrue();
});
