<?php

declare(strict_types=1);

use Aristonis\BlogManager\Enums\MediaKind;
use Aristonis\BlogManager\Exceptions\GenericBlogManagerException;
use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\MediaItem;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Tests\Fixtures\User;
use Illuminate\Support\Facades\Schema;

it('generates an opaque ULID public_id and routes by it', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    expect($post->public_id)->toBeString()->toHaveLength(26)
        ->and($post->getRouteKeyName())->toBe('public_id')
        ->and($post->getKeyName())->toBe('id'); // internal numeric PK stays hidden
});

it('respects configurable table names', function () {
    config()->set('blog-manager.tables.posts', 'custom_posts');

    expect((new Post)->getTable())->toBe('custom_posts');
});

it('orders blocks by position and links post/media relations', function () {
    $post = Post::create(['title' => 'P', 'slug' => 'p']);
    $media = MediaItem::forceCreate([
        'kind' => MediaKind::Image, 'mime' => 'image/png', 'size' => 123,
        'original_filename' => 'a.png', 'adapter' => 'filesystem', 'disk' => 'public', 'path' => 'x/a.png',
    ]);

    ContentBlock::forceCreate(['post_id' => $post->id, 'type' => 'paragraph', 'position' => 1, 'data' => ['format' => 'plain', 'content' => 'two']]);
    ContentBlock::forceCreate(['post_id' => $post->id, 'type' => 'image', 'position' => 0, 'media_item_id' => $media->id, 'data' => ['alt' => 'a']]);

    $ordered = $post->fresh()->blocks;

    expect($ordered->pluck('position')->all())->toBe([0, 1])
        ->and($ordered->first()->mediaItem->is($media))->toBeTrue()
        ->and($ordered->first()->post->is($post))->toBeTrue()
        ->and($media->fresh()->blocks)->toHaveCount(1)
        ->and($ordered->last()->media_item_id)->toBeNull()
        ->and($ordered->last()->data)->toBe(['format' => 'plain', 'content' => 'two']);
});

it('casts media kind to the enum and meta to an array', function () {
    $media = MediaItem::forceCreate([
        'kind' => MediaKind::Video, 'mime' => 'video/mp4', 'size' => 9,
        'original_filename' => 'v.mp4', 'adapter' => 'filesystem', 'disk' => 'public', 'path' => 'v.mp4', 'meta' => ['w' => 1],
    ])->fresh();

    expect($media->kind)->toBe(MediaKind::Video)
        ->and($media->meta)->toBe(['w' => 1])
        ->and($media->size)->toBe(9);
});

it('resolves a configurable, nullable author', function () {
    Schema::create('users', function ($t) {
        $t->id();
        $t->string('name');
    });
    config()->set('blog-manager.author_model', User::class);
    $user = User::create(['name' => 'Ann']);

    $withAuthor = Post::create(['title' => 'A', 'slug' => 'a', 'author_id' => $user->id]);
    $noAuthor = Post::create(['title' => 'B', 'slug' => 'b']);

    expect($withAuthor->author->is($user))->toBeTrue()
        ->and($noAuthor->author_id)->toBeNull()
        ->and($noAuthor->author)->toBeNull();
});

it('throws a clear error when the author relation is used without configuration', function () {
    config()->set('blog-manager.author_model', null);

    Post::create(['title' => 'C', 'slug' => 'c'])->author();
})->throws(GenericBlogManagerException::class);
