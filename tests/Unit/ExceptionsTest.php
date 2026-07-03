<?php

declare(strict_types=1);

use Aristonis\BlogManager\Contracts\HasErrorCode;
use Aristonis\BlogManager\Exceptions\AuthorizationDeniedException;
use Aristonis\BlogManager\Exceptions\BlockKindMismatchException;
use Aristonis\BlogManager\Exceptions\BlockPositionOutOfRangeException;
use Aristonis\BlogManager\Exceptions\BlockTypeNotRegisteredException;
use Aristonis\BlogManager\Exceptions\BlogManagerException;
use Aristonis\BlogManager\Exceptions\CategoryNotFoundException;
use Aristonis\BlogManager\Exceptions\GenericBlogManagerException;
use Aristonis\BlogManager\Exceptions\InvalidBlockDataException;
use Aristonis\BlogManager\Exceptions\InvalidPostDataException;
use Aristonis\BlogManager\Exceptions\InvalidTaxonomyDataException;
use Aristonis\BlogManager\Exceptions\MediaAdapterNotFoundException;
use Aristonis\BlogManager\Exceptions\MediaInUseException;
use Aristonis\BlogManager\Exceptions\MediaStorageFailedException;
use Aristonis\BlogManager\Exceptions\MediaValidationException;
use Aristonis\BlogManager\Exceptions\PostNotFoundException;
use Aristonis\BlogManager\Exceptions\TagNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

dataset('exceptions', [
    'post not found' => [PostNotFoundException::class, 1001, 'blog.post.not_found', 404],
    'invalid post data' => [InvalidPostDataException::class, 1002, 'blog.post.invalid_data', 422],
    'block type not registered' => [BlockTypeNotRegisteredException::class, 2001, 'blog.block.type_not_registered', 422],
    'invalid block data' => [InvalidBlockDataException::class, 2002, 'blog.block.invalid_data', 422],
    'block kind mismatch' => [BlockKindMismatchException::class, 2003, 'blog.block.kind_mismatch', 422],
    'block position out of range' => [BlockPositionOutOfRangeException::class, 2004, 'blog.block.position_out_of_range', 422],
    'media validation' => [MediaValidationException::class, 3001, 'blog.media.validation_failed', 422],
    'media adapter not found' => [MediaAdapterNotFoundException::class, 3002, 'blog.media.adapter_not_found', 500],
    'media in use' => [MediaInUseException::class, 3003, 'blog.media.in_use', 409],
    'media storage failed' => [MediaStorageFailedException::class, 3004, 'blog.media.storage_failed', 500],
    'authorization denied' => [AuthorizationDeniedException::class, 4001, 'blog.authorization.denied', 403],
    'category not found' => [CategoryNotFoundException::class, 5001, 'blog.category.not_found', 404],
    'tag not found' => [TagNotFoundException::class, 5002, 'blog.tag.not_found', 404],
    'invalid taxonomy data' => [InvalidTaxonomyDataException::class, 5003, 'blog.taxonomy.invalid_data', 422],
    'generic' => [GenericBlogManagerException::class, 9001, 'blog.error', 500],
]);

it('exposes its numeric code, text code, http status and context', function (string $class, int $number, string $key, int $status) {
    /** @var BlogManagerException $e */
    $e = new $class('boom', ['id' => 'abc']);

    expect($e)->toBeInstanceOf(BlogManagerException::class)
        ->and($e)->toBeInstanceOf(HasErrorCode::class)
        ->and($e->numberCode())->toBe($number)
        ->and($e->textCode())->toBe($key)
        ->and($e->httpStatus())->toBe($status)
        ->and($e->getCode())->toBe($number)
        ->and($e->getMessage())->toBe('boom')
        ->and($e->context())->toBe(['id' => 'abc']);
})->with('exceptions');

it('falls back to the text code when no message is given', function () {
    expect((new PostNotFoundException)->getMessage())->toBe('blog.post.not_found');
});

it('renders a JSON error only when the client expects JSON', function () {
    $e = new PostNotFoundException('nope', ['post' => '01ABC']);

    $json = $e->render(Request::create('/x', 'GET', server: ['HTTP_ACCEPT' => 'application/json']));
    expect($json)->toBeInstanceOf(JsonResponse::class)
        ->and($json->getStatusCode())->toBe(404)
        ->and($json->getData(true))->toBe([
            'error_code' => 1001,
            'error_key' => 'blog.post.not_found',
            'message' => 'nope',
        ]);

    expect($e->render(Request::create('/x', 'GET')))->toBeNull();
});
