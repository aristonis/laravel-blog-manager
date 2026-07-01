<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Decides whether a user may perform a blog ability. The package defines the
 * ability keys (see Abilities) and delegates the decision here — it never models
 * roles/permissions. Register a driver on the AuthorizationManager to plug in
 * Gate/policies, spatie/laravel-permission, or a custom scheme (no core edit).
 */
interface Authorizer
{
    public function allows(?Authenticatable $user, string $ability, mixed $subject = null): bool;

    /** Throw AuthorizationDeniedException when the ability is not granted. */
    public function authorize(?Authenticatable $user, string $ability, mixed $subject = null): void;
}
