<?php

declare(strict_types=1);

use Aristonis\BlogManager\Authorization\Abilities;
use Aristonis\BlogManager\Authorization\AuthorizationManager;
use Aristonis\BlogManager\Authorization\Authorizers\GateAuthorizer;
use Aristonis\BlogManager\Authorization\Authorizers\NoneAuthorizer;
use Aristonis\BlogManager\Contracts\Authorizer;
use Aristonis\BlogManager\Exceptions\AuthorizationDeniedException;
use Aristonis\BlogManager\Exceptions\AuthorizationDriverNotFoundException;
use Aristonis\BlogManager\Services\PostService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

it('allows everything with the default none driver', function () {
    $authorizer = app(AuthorizationManager::class)->authorizer();

    expect($authorizer)->toBeInstanceOf(NoneAuthorizer::class)
        ->and($authorizer->allows(null, Abilities::POST_CREATE))->toBeTrue();

    expect(fn () => $authorizer->authorize(null, Abilities::POST_DELETE))->not->toThrow(Exception::class);
});

it('resolves the active driver from config', function () {
    config()->set('blog-manager.authorization.driver', 'gate');

    expect(app(AuthorizationManager::class)->authorizer())->toBeInstanceOf(GateAuthorizer::class);
});

it('denies via the gate driver when no policy grants the ability', function () {
    config()->set('blog-manager.authorization.driver', 'gate');
    $authorizer = app(Authorizer::class);

    expect($authorizer->allows(null, Abilities::POST_CREATE))->toBeFalse();
    expect(fn () => $authorizer->authorize(null, Abilities::POST_CREATE))
        ->toThrow(AuthorizationDeniedException::class);
});

it('honors a gate ability that grants access', function () {
    config()->set('blog-manager.authorization.driver', 'gate');
    Gate::define(Abilities::POST_CREATE, fn ($user = null) => true);

    expect(app(Authorizer::class)->allows(null, Abilities::POST_CREATE))->toBeTrue();
});

it('honors a custom registered authorizer', function () {
    $custom = new class implements Authorizer
    {
        public function allows(?Authenticatable $user, string $ability, mixed $subject = null): bool
        {
            return $ability === Abilities::POST_CREATE;
        }

        public function authorize(?Authenticatable $user, string $ability, mixed $subject = null): void
        {
            if (! $this->allows($user, $ability, $subject)) {
                throw new AuthorizationDeniedException('denied');
            }
        }
    };

    app(AuthorizationManager::class)->extend('custom', fn () => $custom);
    config()->set('blog-manager.authorization.driver', 'custom');

    $authorizer = app(AuthorizationManager::class)->authorizer();
    expect($authorizer->allows(null, Abilities::POST_CREATE))->toBeTrue()
        ->and($authorizer->allows(null, Abilities::POST_DELETE))->toBeFalse();
});

it('fails loud on an unknown driver', function () {
    config()->set('blog-manager.authorization.driver', 'bogus');

    expect(fn () => app(AuthorizationManager::class)->authorizer())
        ->toThrow(AuthorizationDriverNotFoundException::class);
});

it('enforces abilities in the service layer only when enabled', function () {
    config()->set('blog-manager.authorization.driver', 'gate'); // denies without a policy

    // default: enforce_in_services = false -> service call is allowed
    expect(app(PostService::class)->create(['title' => 'Open'])->slug)
        ->toBe('open');

    // enabled -> the same call is denied at the service layer
    config()->set('blog-manager.authorization.enforce_in_services', true);
    expect(fn () => app(PostService::class)->create(['title' => 'Blocked']))
        ->toThrow(AuthorizationDeniedException::class);
});

it('exposes the ability keys', function () {
    expect(Abilities::all())->toContain(
        'blog.post.create', 'blog.post.update', 'blog.post.delete',
        'blog.block.manage', 'blog.media.upload', 'blog.media.delete',
        'blog.taxonomy.manage',
    );
});
