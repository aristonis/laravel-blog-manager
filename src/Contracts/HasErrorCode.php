<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Contracts;

/**
 * Marks an exception as carrying the package's stable error contract:
 * a numeric code plus a string (machine) code. These two values are a public
 * API — clients may switch on them — so they must not change lightly.
 */
interface HasErrorCode
{
    /** Stable numeric error code (see BlogManagerException ranges). */
    public function numberCode(): int;

    /** Stable machine-readable string code, e.g. "blog.media.validation_failed". */
    public function textCode(): string;

    /**
     * Extra, non-sensitive context for logging/diagnostics.
     *
     * @return array<string, mixed>
     */
    public function context(): array;
}
