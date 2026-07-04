<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Media;

use Aristonis\BlogManager\Exceptions\MediaValidationException;

/**
 * Immutable input to the storage port: a single binary source described by exactly
 * one of a filesystem {@see self::$path} XOR an open {@see self::$stream}, plus the
 * caller-supplied `mime`, `originalFilename`, and `size` metadata.
 *
 * Construction rules (FR-80):
 * - a `path` is a NON-EMPTY string — an empty string is treated as absent;
 * - a `stream` is a PHP `resource` (an `fopen()` result); a PSR-7 `StreamInterface`
 *   is NOT accepted (the package takes no PSR-7 dependency);
 * - providing NEITHER or BOTH fails loud with {@see MediaValidationException}.
 *
 * The package never re-detects the MIME from the bytes: the caller owns the
 * transport and supplies `mime` (FR-84). Stream ownership stays with the caller —
 * the adapter that consumes this VO reads the stream but never closes it (O-3).
 */
final class MediaSource
{
    /**
     * The filesystem path to read the binary from, or null when a stream is supplied.
     *
     * The package TRUSTS this path: the adapter reads it as-is (via the Laravel
     * filesystem) and performs NO path confinement. The caller is responsible for
     * ensuring it points to a file the application legitimately owns — never a
     * path derived from unsanitized external input.
     */
    public readonly ?string $path;

    /** The original client filename, with control characters stripped (FR-85). */
    public readonly string $originalFilename;

    /**
     * An open, caller-owned stream to read the binary from, or null when a path is
     * supplied. Private because PHP has no `resource` type declaration; effectively
     * immutable — enforced by visibility (read via {@see self::stream()}).
     *
     * @var resource|null
     */
    private $stream;

    /**
     * @param  string|null  $path  a non-empty filesystem path, or null/'' when absent
     * @param  resource|null  $stream  an open PHP stream resource, or null when absent
     * @param  string  $mime  the caller-supplied MIME type (not re-sniffed here)
     * @param  string  $originalFilename  the original client filename
     * @param  int  $size  the byte length, or 0 when unknown (e.g. an unbounded stream)
     *
     * @throws MediaValidationException when the stream is a non-resource, when
     *                                  neither or both of path/stream are provided,
     *                                  or when the size is negative
     */
    public function __construct(
        ?string $path,
        mixed $stream,
        public readonly string $mime,
        string $originalFilename,
        public readonly int $size,
    ) {
        // A supplied-but-invalid stream is rejected outright: only a PHP resource
        // (or an explicit null) is accepted here — no PSR-7, no path-like strings.
        if ($stream !== null && ! is_resource($stream)) {
            throw new MediaValidationException(
                'MediaSource stream must be a PHP resource.',
                ['stream_type' => get_debug_type($stream)],
            );
        }

        $normalizedPath = ($path !== null && $path !== '') ? $path : null;
        $hasPath = $normalizedPath !== null;
        $hasStream = is_resource($stream);

        // Exactly one source is required. Equal booleans mean neither (both false)
        // or both (both true) — either way the VO cannot resolve a single binary.
        if ($hasPath === $hasStream) {
            throw new MediaValidationException(
                'MediaSource requires exactly one of a non-empty path or a stream resource.',
                ['has_path' => $hasPath, 'has_stream' => $hasStream],
            );
        }

        // 0 is a valid "unknown length" sentinel (O-4); a negative byte count is not.
        if ($size < 0) {
            throw new MediaValidationException(
                'MediaSource size must be 0 (unknown) or a positive byte count.',
                ['size' => $size],
            );
        }

        $this->path = $normalizedPath;
        $this->stream = $hasStream ? $stream : null;

        // Strip control characters from the human-readable filename, mirroring the
        // MIME sanitize (FR-85): the name lands verbatim in MediaItem.original_filename
        // and error contexts, so no CRLF / control bytes may leak downstream.
        $this->originalFilename = preg_replace('/[\p{C}]/u', '', $originalFilename) ?? '';
    }

    /**
     * The open, caller-owned stream for a stream source, or null for a path source.
     * The consuming adapter reads but never closes it (O-3).
     *
     * @return resource|null
     */
    public function stream()
    {
        return $this->stream;
    }
}
