<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Authorization;

use Aristonis\BlogManager\Authorization\Authorizers\GateAuthorizer;
use Aristonis\BlogManager\Authorization\Authorizers\NoneAuthorizer;
use Aristonis\BlogManager\Contracts\Authorizer;
use Aristonis\BlogManager\Exceptions\AuthorizationDriverNotFoundException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Driver registry for authorizers (Illuminate Manager pattern). The active driver
 * comes from `config('blog-manager.authorization.driver')` (default `none`);
 * register a custom one with `extend('name', fn () => new MyAuthorizer)` — no core edit.
 */
final class AuthorizationManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $driver = $this->config->get('blog-manager.authorization.driver', 'none');

        return is_string($driver) ? $driver : 'none';
    }

    public function createNoneDriver(): Authorizer
    {
        return new NoneAuthorizer;
    }

    public function createGateDriver(): Authorizer
    {
        return new GateAuthorizer($this->container->make(Gate::class));
    }

    /** Typed accessor for the active (or named) authorizer. */
    public function authorizer(?string $name = null): Authorizer
    {
        /** @var Authorizer $authorizer */
        $authorizer = $this->driver($name);

        return $authorizer;
    }

    /**
     * @param  string  $driver
     */
    protected function createDriver($driver): mixed
    {
        try {
            return parent::createDriver($driver);
        } catch (InvalidArgumentException) {
            throw new AuthorizationDriverNotFoundException(
                'Authorization driver ['.$driver.'] is not registered.',
                ['driver' => $driver],
            );
        }
    }
}
