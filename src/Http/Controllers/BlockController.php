<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Http\Controllers;

use Aristonis\BlogManager\Http\Resources\BlockResource;
use Aristonis\BlogManager\Models\ContentBlock;
use Aristonis\BlogManager\Models\MediaItem;
use Aristonis\BlogManager\Models\Post;
use Aristonis\BlogManager\Services\BlockService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Thin JSON adapter over BlockService. */
final class BlockController
{
    public function __construct(private readonly BlockService $blocks) {}

    public function store(Request $request, Post $post): BlockResource
    {
        $media = null;
        $mediaId = $request->input('media_id');
        if (is_string($mediaId) && $mediaId !== '') {
            /** @var MediaItem $media */
            $media = MediaItem::query()->where('public_id', $mediaId)->firstOrFail();
        }

        $data = $request->input('data', []);

        $block = $this->blocks->append(
            $post,
            (string) $request->input('type', ''),
            is_array($data) ? $data : [],
            $media,
        );

        return new BlockResource($block);
    }

    public function update(Request $request, ContentBlock $block): BlockResource
    {
        $data = $request->input('data', []);

        return new BlockResource($this->blocks->update($block, is_array($data) ? $data : []));
    }

    public function destroy(ContentBlock $block): Response
    {
        $this->blocks->remove($block);

        return response()->noContent();
    }

    public function reorder(Request $request, Post $post): Response
    {
        $order = $request->input('order', []);
        $order = array_values(array_map('strval', is_array($order) ? $order : []));

        $this->blocks->reorder($post, $order);

        return response()->noContent();
    }
}
