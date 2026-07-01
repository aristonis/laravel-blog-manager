<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Media;

use Aristonis\BlogManager\Contracts\MediaStorageAdapter;
use Aristonis\BlogManager\Exceptions\MediaAdapterNotFoundException;
use Aristonis\BlogManager\Media\Adapters\FilesystemAdapter;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Driver registry for media storage adapters (Illuminate Manager pattern). The
 * active adapter is chosen by `config('blog-manager.media.adapter')`; register a
 * custom one with `extend('name', fn () => new MyAdapter)` — no core edit (OCP).
 */
final class MediaAdapterManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $adapter = $this->config->get('blog-manager.media.adapter', 'filesystem');

        return is_string($adapter) ? $adapter : 'filesystem';
    }

    public function createFilesystemDriver(): MediaStorageAdapter
    {
        return new FilesystemAdapter;
    }

    /** Typed accessor for the active (or named) adapter. */
    public function adapter(?string $name = null): MediaStorageAdapter
    {
        /** @var MediaStorageAdapter $adapter */
        $adapter = $this->driver($name);

        return $adapter;
    }

    /**
     * @param  string  $driver
     */
    protected function createDriver($driver): mixed
    {
        try {
            return parent::createDriver($driver);
        } catch (InvalidArgumentException) {
            throw new MediaAdapterNotFoundException(
                'Media adapter ['.$driver.'] is not registered.',
                ['adapter' => $driver],
            );
        }
    }
}
