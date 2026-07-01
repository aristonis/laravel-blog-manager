<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Blocks;

use Aristonis\BlogManager\Contracts\BlockType;
use Aristonis\BlogManager\Exceptions\BlockTypeNotRegisteredException;

/**
 * The extension point for block types. Register a BlockType to add a new kind of
 * block — no core edit required (OCP). Bound as a singleton and seeded with the
 * defaults by the service provider.
 */
final class BlockTypeRegistry
{
    /** @var array<string, BlockType> */
    private array $types = [];

    public function register(BlockType $type): void
    {
        $this->types[$type->key()] = $type;
    }

    public function get(string $key): BlockType
    {
        return $this->types[$key]
            ?? throw new BlockTypeNotRegisteredException(
                "Block type [{$key}] is not registered.",
                ['type' => $key],
            );
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->types);
    }
}
