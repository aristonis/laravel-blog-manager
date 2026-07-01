<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Http\Controllers;

use Aristonis\BlogManager\Exceptions\MediaValidationException;
use Aristonis\BlogManager\Http\Resources\MediaResource;
use Aristonis\BlogManager\Media\MediaManager;
use Aristonis\BlogManager\Models\MediaItem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

/** Thin JSON adapter over MediaManager. */
final class MediaController
{
    public function __construct(private readonly MediaManager $media) {}

    public function store(Request $request): MediaResource
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw new MediaValidationException('A file upload named [file] is required.', ['field' => 'file']);
        }

        return new MediaResource($this->media->store($file));
    }

    public function destroy(MediaItem $media): Response
    {
        $this->media->delete($media);

        return response()->noContent();
    }
}
