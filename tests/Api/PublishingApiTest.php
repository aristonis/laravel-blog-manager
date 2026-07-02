<?php

declare(strict_types=1);

use Aristonis\BlogManager\Services\PostService;

it('publishes and unpublishes a post over JSON, exposing status', function () {
    $id = $this->postJson('blog/api/posts', ['title' => 'Launch'])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'draft')
        ->json('data.id');

    $this->postJson("blog/api/posts/{$id}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    $this->postJson("blog/api/posts/{$id}/unpublish")
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');
});

it('hides drafts from public reads and 404s a hidden post under a restricting driver', function () {
    $svc = app(PostService::class);
    $svc->create(['title' => 'Draft']);
    $live = $svc->publish($svc->create(['title' => 'Live']));

    config()->set('blog-manager.authorization.driver', 'gate'); // anon lacks blog.post.update

    $this->getJson('blog/api/posts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $live->public_id);

    $this->getJson("blog/api/posts/{$live->public_id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    $draftId = $svc->find('draft')->public_id; // slug 'draft'
    $this->getJson("blog/api/posts/{$draftId}")->assertNotFound();
});

it('schedules a post via the API with a future published_at', function () {
    $id = $this->postJson('blog/api/posts', ['title' => 'Later'])->json('data.id');

    $this->postJson("blog/api/posts/{$id}/publish", ['published_at' => now()->addDay()->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    // Scheduled (future published_at) -> hidden from published-only reads.
    config()->set('blog-manager.authorization.driver', 'gate');
    $this->getJson("blog/api/posts/{$id}")->assertNotFound();
});

it('includes drafts for a caller that holds the update ability (default allow-all)', function () {
    app(PostService::class)->create(['title' => 'Draft']);

    // default driver is `none` -> the caller holds every ability -> drafts are visible
    $this->getJson('blog/api/posts')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
