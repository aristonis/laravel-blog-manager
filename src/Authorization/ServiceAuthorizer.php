<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Authorization;

use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * Optional service-layer ability enforcement. When
 * `config('blog-manager.authorization.enforce_in_services')` is true, the
 * services call this before a mutation; otherwise it is a no-op and the host is
 * responsible for authorizing in its own transport layer. Resolves the current
 * user from the host guard.
 */
final class ServiceAuthorizer
{
    public function __construct(
        private readonly AuthorizationManager $manager,
        private readonly AuthFactory $auth,
    ) {}

    public function ensure(string $ability, mixed $subject = null): void
    {
        if (! (bool) config('blog-manager.authorization.enforce_in_services')) {
            return;
        }

        $this->manager->authorizer()->authorize($this->auth->guard()->user(), $ability, $subject);
    }
}
