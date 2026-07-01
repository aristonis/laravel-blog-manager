<?php

declare(strict_types=1);

it('registers no routes when the API is disabled (default)', function () {
    expect(config('blog-manager.api.enabled'))->toBeFalse();

    $this->getJson('blog/api/posts')->assertNotFound();
});
