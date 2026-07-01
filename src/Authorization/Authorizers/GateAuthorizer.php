<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Authorization\Authorizers;

use Aristonis\BlogManager\Contracts\Authorizer;
use Aristonis\BlogManager\Exceptions\AuthorizationDeniedException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Delegates ability checks to Laravel's Gate (and thus the host's policies).
 * Undefined abilities are denied, so hosts must register policies to grant access.
 */
final class GateAuthorizer implements Authorizer
{
    public function __construct(private readonly Gate $gate) {}

    public function allows(?Authenticatable $user, string $ability, mixed $subject = null): bool
    {
        $gate = $user !== null ? $this->gate->forUser($user) : $this->gate;

        return $gate->check($ability, $subject === null ? [] : [$subject]);
    }

    public function authorize(?Authenticatable $user, string $ability, mixed $subject = null): void
    {
        if (! $this->allows($user, $ability, $subject)) {
            throw new AuthorizationDeniedException(
                "Denied ability [{$ability}].",
                ['ability' => $ability],
            );
        }
    }
}
