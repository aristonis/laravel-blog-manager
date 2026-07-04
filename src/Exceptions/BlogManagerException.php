<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Exceptions;

use Aristonis\BlogManager\Contracts\HasErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Base for every package exception.
 *
 * Each concrete exception defines its own two values as class constants —
 * a numeric {@see self::NUMBER_CODE} and a string {@see self::TEXT_CODE} — plus
 * an HTTP status. There is deliberately no central enum; the two values live on
 * the exception itself.
 *
 * Number ranges: 1xxx posts · 2xxx blocks · 3xxx media · 4xxx authorization · 5xxx taxonomy · 6xxx seo · 9xxx generic.
 *
 * The exception self-renders to a JSON envelope for API clients (see {@see render()}).
 * It never registers a global handler or otherwise touches the host application's
 * exception handling.
 */
abstract class BlogManagerException extends RuntimeException implements HasErrorCode
{
    public const NUMBER_CODE = 9000;

    public const TEXT_CODE = 'blog.error';

    protected int $httpStatus = 400;

    /** @var array<string, mixed> */
    protected array $context;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message = '', array $context = [], ?Throwable $previous = null)
    {
        parent::__construct(
            $message !== '' ? $message : static::TEXT_CODE,
            static::NUMBER_CODE,
            $previous,
        );

        $this->context = $context;
    }

    public function numberCode(): int
    {
        return static::NUMBER_CODE;
    }

    public function textCode(): string
    {
        return static::TEXT_CODE;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Render to the package's JSON error envelope, but only when the caller
     * expects JSON. Returning null lets the host's handler render the exception
     * however it normally would — the global handler is never overridden.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return new JsonResponse([
            'error_code' => $this->numberCode(),
            'error_key' => $this->textCode(),
            'message' => $this->getMessage(),
        ], $this->httpStatus());
    }
}
