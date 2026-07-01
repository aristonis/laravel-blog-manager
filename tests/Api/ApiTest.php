<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('creates, shows, updates and deletes a post over JSON', function () {
    $create = $this->postJson('blog/api/posts', ['title' => 'Hello world'])
        ->assertSuccessful()
        ->assertJsonPath('data.slug', 'hello-world');

    $id = $create->json('data.id');
    expect($id)->toHaveLength(26); // opaque ULID, never the numeric key

    $this->postJson("blog/api/posts/{$id}/blocks", ['type' => 'paragraph', 'data' => ['format' => 'plain', 'content' => 'hi']])
        ->assertSuccessful()
        ->assertJsonPath('data.type', 'paragraph');

    $this->getJson("blog/api/posts/{$id}")
        ->assertOk()
        ->assertJsonPath('data.blocks.0.payload.html', '<p>hi</p>');

    $this->putJson("blog/api/posts/{$id}", ['title' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed');

    $this->deleteJson("blog/api/posts/{$id}")->assertNoContent();
    $this->getJson("blog/api/posts/{$id}")->assertNotFound();
});

it('reorders blocks over JSON', function () {
    $id = $this->postJson('blog/api/posts', ['title' => 'P'])->json('data.id');
    $b0 = $this->postJson("blog/api/posts/{$id}/blocks", ['type' => 'heading', 'data' => ['text' => 'a']])->json('data.id');
    $b1 = $this->postJson("blog/api/posts/{$id}/blocks", ['type' => 'paragraph', 'data' => ['content' => 'b']])->json('data.id');

    $this->postJson("blog/api/posts/{$id}/blocks/reorder", ['order' => [$b1, $b0]])->assertNoContent();

    $blocks = $this->getJson("blog/api/posts/{$id}")->json('data.blocks');
    expect($blocks[0]['id'])->toBe($b1)->and($blocks[1]['id'])->toBe($b0);
});

it('uploads and deletes media over JSON', function () {
    Storage::fake('public');

    $res = $this->post('blog/api/media', ['file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])
        ->assertSuccessful()
        ->assertJsonPath('data.kind', 'image');

    $id = $res->json('data.id');
    $this->deleteJson("blog/api/media/{$id}")->assertNoContent();
});

it('renders a package exception as a JSON error envelope', function () {
    $id = $this->postJson('blog/api/posts', ['title' => 'P'])->json('data.id');

    // an image block with no media -> BlockKindMismatch (2003 / 422)
    $this->postJson("blog/api/posts/{$id}/blocks", ['type' => 'image', 'data' => ['alt' => 'x']])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 2003)
        ->assertJsonPath('error_key', 'blog.block.kind_mismatch');
});

it('enforces abilities at the API edge with the gate driver', function () {
    config()->set('blog-manager.authorization.driver', 'gate'); // denies without a policy

    $this->postJson('blog/api/posts', ['title' => 'X'])
        ->assertStatus(403)
        ->assertJsonPath('error_code', 4001);

    // reads are not ability-guarded
    $this->getJson('blog/api/posts')->assertOk();
});
