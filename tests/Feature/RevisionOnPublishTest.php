<?php

declare(strict_types=1);

use Aristonis\BlogManager\Events\PostRevisionCreated;
use Aristonis\BlogManager\Services\PostService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('captures a revision automatically when a post is published', function () {
    Event::fake([PostRevisionCreated::class]);
    $post = app(PostService::class)->create(['title' => 'Ship it']);

    expect($post->fresh()->revisions)->toHaveCount(0);

    app(PostService::class)->publish($post);

    $revisions = $post->fresh()->revisions;
    expect($revisions)->toHaveCount(1)
        ->and($revisions->first()->label)->toBe('published')
        ->and($revisions->first()->snapshot['post']['status'])->toBe('published');

    Event::assertDispatched(PostRevisionCreated::class);
});

it('does not capture on publish when snapshot_on_publish is disabled', function () {
    config()->set('blog-manager.revisions.snapshot_on_publish', false);
    $post = app(PostService::class)->create(['title' => 'Quiet']);

    app(PostService::class)->publish($post);

    expect($post->fresh()->revisions)->toHaveCount(0);
});

it('captures no revision when the publish transaction rolls back', function () {
    $post = app(PostService::class)->create(['title' => 'Rollback']);

    try {
        DB::transaction(function () use ($post): void {
            app(PostService::class)->publish($post);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // swallow — asserting the auto-snapshot rolled back with the publish
    }

    expect($post->fresh()->revisions)->toHaveCount(0)
        ->and($post->fresh()->status->value)->toBe('draft');
});
