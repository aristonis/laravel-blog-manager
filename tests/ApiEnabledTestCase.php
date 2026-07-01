<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Tests;

/**
 * Boots the package with the optional API enabled, so the provider registers the
 * routes for real. Host middleware is cleared to keep the suite auth-agnostic.
 */
class ApiEnabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('blog-manager.api.enabled', true);
        $app['config']->set('blog-manager.api.middleware', []);
    }
}
