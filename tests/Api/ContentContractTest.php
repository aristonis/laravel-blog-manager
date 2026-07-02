<?php

declare(strict_types=1);

it('exposes both raw source and rendered payload for a block, on create and on read', function () {
    $id = $this->postJson('blog/api/posts', ['title' => 'P'])->json('data.id');

    $created = $this->postJson(
        "blog/api/posts/{$id}/blocks",
        ['type' => 'paragraph', 'data' => ['format' => 'markdown', 'content' => '**hi**']],
    )->assertSuccessful();

    expect($created->json('data.source'))->toBe(['format' => 'markdown', 'content' => '**hi**'])
        ->and($created->json('data.payload.html'))->toContain('<strong>hi</strong>');

    $block = $this->getJson("blog/api/posts/{$id}")->json('data.blocks.0');

    expect($block['source'])->toBe(['format' => 'markdown', 'content' => '**hi**'])
        ->and($block['payload']['html'])->toContain('<strong>hi</strong>');
});
