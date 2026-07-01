<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Http\Middleware;

use Aristonis\BlogManager\Contracts\Authorizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces a blog ability at the API edge via the active Authorizer. Denials
 * throw AuthorizationDeniedException, which self-renders to a 403 JSON error.
 * With the default `none` driver every ability is allowed.
 */
final class EnsureAbility
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $this->authorizer->authorize($request->user(), $ability);

        return $next($request);
    }
}
