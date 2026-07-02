<?php

declare(strict_types=1);

use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Services\BlockService;
use Aristonis\BlogManager\Services\PostService;
use Illuminate\Database\QueryException;

it('rejects a duplicate (post_id, position) at the database level', function () {
    $post = Post::create(['title' => 'P', 'slug' => 'p']);
    ContentBlock::create(['post_id' => $post->id, 'type' => 'paragraph', 'position' => 0, 'data' => ['content' => 'a']]);

    expect(fn () => ContentBlock::create(
        ['post_id' => $post->id, 'type' => 'paragraph', 'position' => 0, 'data' => ['content' => 'b']]
    ))->toThrow(QueryException::class);
});

it('reverses a full block order without a transient unique collision', function () {
    $svc = app(PostService::class);
    $blocks = app(BlockService::class);
    $post = $svc->create(['title' => 'P']);

    $a = $blocks->append($post, 'paragraph', ['content' => 'a']);
    $b = $blocks->append($post, 'paragraph', ['content' => 'b']);
    $c = $blocks->append($post, 'paragraph', ['content' => 'c']);
    $d = $blocks->append($post, 'paragraph', ['content' => 'd']);

    $blocks->reorder($post, [$d->public_id, $c->public_id, $b->public_id, $a->public_id]);

    $ordered = $post->fresh()->blocks;
    expect($ordered->pluck('public_id')->all())
        ->toBe([$d->public_id, $c->public_id, $b->public_id, $a->public_id])
        ->and($ordered->pluck('position')->all())->toBe([0, 1, 2, 3]);
});
