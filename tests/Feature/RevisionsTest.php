<?php

declare(strict_types=1);

use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Models\PostRevision;

it('persists a revision with a ULID public_id, an array-cast snapshot, and a post relation', function () {
    $post = Post::create(['title' => 'Hello', 'slug' => 'hello']);

    $snapshot = [
        'post' => ['title' => 'Hello', 'slug' => 'hello'],
        'blocks' => [['type' => 'heading', 'position' => 0, 'source' => ['text' => 'Hi']]],
    ];

    $revision = PostRevision::create([
        'post_id' => $post->id,
        'snapshot' => $snapshot,
        'label' => 'published',
        'created_by' => 7,
    ]);

    $fresh = $revision->fresh();

    expect($fresh->public_id)->toBeString()->toHaveLength(26)
        ->and($fresh->snapshot)->toBe($snapshot)            // JSON cast round-trips to an array
        ->and($fresh->label)->toBe('published')
        ->and($fresh->created_by)->toEqual(7)
        ->and($fresh->post->is($post))->toBeTrue()
        ->and($revision->getRouteKeyName())->toBe('public_id')
        ->and($revision->getKeyName())->toBe('id');         // internal numeric PK stays hidden
});

it('lists a post\'s revisions newest-first via the relation', function () {
    $post = Post::create(['title' => 'P', 'slug' => 'p']);

    $first = PostRevision::create(['post_id' => $post->id, 'snapshot' => ['n' => 1]]);
    $second = PostRevision::create(['post_id' => $post->id, 'snapshot' => ['n' => 2]]);
    $third = PostRevision::create(['post_id' => $post->id, 'snapshot' => ['n' => 3]]);

    expect($post->fresh()->revisions->pluck('id')->all())
        ->toBe([$third->id, $second->id, $first->id]);      // newest first
});

it('respects a configurable revisions table name', function () {
    config()->set('blog-manager.tables.post_revisions', 'custom_revisions');

    expect((new PostRevision)->getTable())->toBe('custom_revisions');
});
