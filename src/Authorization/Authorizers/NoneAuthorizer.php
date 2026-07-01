<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Authorization\Authorizers;

use Aristonis\BlogManager\Contracts\Authorizer;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Allow-all authorizer — the default. Keeps the backend usable on install; the
 * host opts into real enforcement by switching the driver.
 */
final class NoneAuthorizer implements Authorizer
{
    public function allows(?Authenticatable $user, string $ability, mixed $subject = null): bool
    {
        return true;
    }

    public function authorize(?Authenticatable $user, string $ability, mixed $subject = null): void
    {
        // Always allowed.
    }
}
