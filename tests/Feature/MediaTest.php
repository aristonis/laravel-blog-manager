<?php

declare(strict_types=1);

use Aristonis\BlogManager\Contracts\MediaStorageAdapter;
use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Exceptions\MediaInUseException;
use Aristonis\BlogManager\Exceptions\MediaStorageFailedException;
use Aristonis\BlogManager\Exceptions\MediaValidationException;
use Aristonis\BlogManager\Media\MediaAdapterManager;
use Aristonis\BlogManager\Media\MediaKindResolver;
use Aristonis\BlogManager\Media\MediaManager;
use Aristonis\BlogManager\Media\StoredMediaRef;
use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\MediaItem;
use Aristonis\BlogManager\Models\Post;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('classifies media kind from mime', function () {
    $resolver = app(MediaKindResolver::class);

    expect($resolver->resolve('image/png'))->toBe(MediaKind::Image)
        ->and($resolver->resolve('video/mp4'))->toBe(MediaKind::Video)
        ->and($resolver->resolve('application/pdf'))->toBe(MediaKind::File);
});

it('stores a valid image and records a first-class media item', function () {
    $media = app(MediaManager::class)->store(UploadedFile::fake()->image('a.png'));

    expect($media->kind)->toBe(MediaKind::Image)
        ->and($media->public_id)->toHaveLength(26)
        ->and($media->mime)->toBe('image/png')
        ->and($media->adapter)->toBe('filesystem');

    Storage::disk('public')->assertExists($media->path);
});

it('rejects a disallowed mime and stores nothing', function () {
    expect(fn () => app(MediaManager::class)->store(UploadedFile::fake()->create('x.txt', 1, 'text/plain')))
        ->toThrow(MediaValidationException::class);

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('rejects an oversize file', function () {
    config()->set('blog-manager.media.max_size.image', 10);

    expect(fn () => app(MediaManager::class)->store(UploadedFile::fake()->image('big.png')))
        ->toThrow(MediaValidationException::class);
});

it('routes storage through a custom adapter and compensates on record failure', function () {
    $fake = new class implements MediaStorageAdapter
    {
        /** @var list<string> */
        public array $deleted = [];

        public function name(): string
        {
            return 'fake';
        }

        public function store(UploadedFile $file, MediaKind $kind): StoredMediaRef
        {
            return new StoredMediaRef('fake', 'd', 'fake/path.bin');
        }

        public function url(MediaItem $item, ?int $ttlMinutes = null): ?string
        {
            return 'http://fake/'.$item->path;
        }

        public function delete(MediaItem $item): void
        {
            $this->deleted[] = (string) $item->path;
        }
    };

    app(MediaAdapterManager::class)->extend('fake', fn () => $fake);
    config()->set('blog-manager.media.adapter', 'fake');
    config()->set('blog-manager.media.allowed_mime.image', ['image/png']);

    // swap works without any core edit
    $media = app(MediaManager::class)->store(UploadedFile::fake()->image('a.png'));
    expect($media->adapter)->toBe('fake')->and($media->path)->toBe('fake/path.bin');

    // force the DB record to fail -> the stored binary is compensated (no orphan)
    Schema::drop('blog_media_items');
    expect(fn () => app(MediaManager::class)->store(UploadedFile::fake()->image('b.png')))
        ->toThrow(MediaStorageFailedException::class);

    expect($fake->deleted)->toBe(['fake/path.bin']);
});

it('refuses to delete media still referenced by a block', function () {
    $media = MediaItem::forceCreate([
        'kind' => MediaKind::Image, 'mime' => 'image/png', 'size' => 10,
        'original_filename' => 'a.png', 'adapter' => 'filesystem', 'disk' => 'public', 'path' => 'blog-media/a.png',
    ]);
    $post = Post::create(['title' => 'p', 'slug' => 'p']);
    ContentBlock::forceCreate(['post_id' => $post->id, 'type' => 'image', 'position' => 0, 'media_item_id' => $media->id]);

    expect(fn () => app(MediaManager::class)->delete($media))->toThrow(MediaInUseException::class)
        ->and(MediaItem::find($media->id))->not->toBeNull();
});

it('deletes an unreferenced media item and its binary', function () {
    $media = app(MediaManager::class)->store(UploadedFile::fake()->image('a.png'));
    $path = $media->path;

    app(MediaManager::class)->delete($media);

    expect(MediaItem::find($media->id))->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('resolves a url for a media item', function () {
    $media = app(MediaManager::class)->store(UploadedFile::fake()->image('a.png'));

    expect(app(MediaManager::class)->url($media))->toBeString();
});

it('returns a usable url (never throws) for a ttl on a disk without signed-url support (L2)', function () {
    // A bare local disk rejects temporaryUrl() with a raw RuntimeException.
    // Requesting a signed URL on it must degrade to the plain url() (or null),
    // never surfacing that exception to the host.
    config()->set('filesystems.disks.l2_local', [
        'driver' => 'local',
        'root' => sys_get_temp_dir().'/blog-manager-l2',
    ]);

    $item = (new MediaItem)->forceFill([
        'adapter' => 'filesystem',
        'disk' => 'l2_local',
        'path' => 'x.png',
    ]);

    // Pre-fix: temporaryUrl() throws a raw RuntimeException here (RED).
    // Post-fix: degrades to the plain url() (a string), never throwing (GREEN).
    $url = app(MediaManager::class)->url($item, 5);

    expect($url === null || is_string($url))->toBeTrue();
});

it('strips control characters from the mime in the validation message (M8)', function () {
    // The resolved mime can fall back to the attacker-controlled client mime.
    // A CRLF-laden value must NOT reach the exception MESSAGE (log/CRLF injection),
    // while the raw value is preserved in the exception context for forensics.
    $injected = "application/pdf\n[CRITICAL] injected";

    try {
        app(MediaManager::class)->store(UploadedFile::fake()->create('x.bin', 1, $injected));
        test()->fail('Expected MediaValidationException to be thrown.');
    } catch (MediaValidationException $e) {
        expect($e->getMessage())->not->toContain("\n")
            ->and(preg_match('/[\p{C}]/u', $e->getMessage()))->toBe(0)
            ->and($e->context()['mime'])->toBe($injected); // raw value retained
    }
});
