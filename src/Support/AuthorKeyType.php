<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use InvalidArgumentException;

/**
 * Resolves and applies the host-configured author-reference key type. A pure
 * static leaf (mirrors the Support/SlugGenerator precedent): it holds no state
 * and has no service dependency, so both anonymous-class migrations AND
 * BlogManagerServiceProvider::boot() can `use`-import it with zero container
 * wiring — the single source of truth for the allowed set, the fail-loud gate,
 * and the column emitted for the `author_id` / `created_by` references.
 *
 * The `bigint` arm emits exactly `unsignedBigInteger`, so the default install
 * schema is byte-for-byte identical to pre-M3 (NFR-30); `uuid`/`ulid` swap the
 * column type without any DB foreign key (the author table belongs to the host).
 */
final class AuthorKeyType
{
    /**
     * The accepted `blog-manager.author_key_type` values, in declaration order.
     * `bigint` (the default) is a numeric host key; `uuid`/`ulid` are 36-/26-char
     * string keys. This list backs both validation and the fail-loud message.
     *
     * @var list<string>
     */
    public const ALLOWED = ['bigint', 'uuid', 'ulid'];

    /**
     * The default key when the host has not configured one — the pre-M3 shape.
     */
    private const DEFAULT = 'bigint';

    /**
     * Read, validate and return the configured author key type. Throws fail-loud
     * on any value outside {@see self::ALLOWED} — this is a host configuration /
     * programmer error (LogicException family), not a runtime/domain condition,
     * so it surfaces an InvalidArgumentException. The throw runs before any
     * Schema::create() (the migration resolves via apply()) and at the top of
     * boot(), so a malformed value fails at application bootstrap rather than
     * leaving a partial table behind (FR-78, AC-56, O-1).
     *
     * @throws InvalidArgumentException when the configured value is not allowed
     */
    public static function resolve(): string
    {
        $configured = config('blog-manager.author_key_type', self::DEFAULT);
        $value = is_string($configured) ? $configured : '';

        if (! in_array($value, self::ALLOWED, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid blog-manager.author_key_type [%s]; allowed: %s.',
                is_scalar($configured) ? (string) $configured : gettype($configured),
                implode(', ', self::ALLOWED),
            ));
        }

        return $value;
    }

    /**
     * Emit the author-reference column onto $table under $column, typed to the
     * resolved key. Returns the ColumnDefinition so the caller chains the
     * per-site modifiers — `->nullable()->index()` on posts, `->nullable()` on
     * revisions (FR-77: created_by carries no standalone index). Resolving here
     * means a bad config throws before the surrounding Schema::create() commits
     * any table (AC-56).
     *
     * @throws InvalidArgumentException when the configured value is not allowed
     */
    public static function apply(Blueprint $table, string $column): ColumnDefinition
    {
        return match (self::resolve()) {
            'uuid' => $table->uuid($column),
            'ulid' => $table->ulid($column),
            default => $table->unsignedBigInteger($column),
        };
    }
}
