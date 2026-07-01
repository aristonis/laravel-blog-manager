<?php

declare(strict_types=1);

use Aristonis\BlogManager\Authorization\Abilities;
use Aristonis\BlogManager\Http\Controllers\BlockController;
use Aristonis\BlogManager\Http\Controllers\MediaController;
use Aristonis\BlogManager\Http\Controllers\PostController;
use Aristonis\BlogManager\Http\Middleware\EnsureAbility;
use Illuminate\Support\Facades\Route;

// Registered by the service provider only when config('blog-manager.api.enabled')
// is true, inside a group carrying the configured prefix, middleware and throttle.

Route::get('posts', [PostController::class, 'index']);
Route::get('posts/{post}', [PostController::class, 'show']);
Route::post('posts', [PostController::class, 'store'])
    ->middleware(EnsureAbility::class.':'.Abilities::POST_CREATE);
Route::put('posts/{post}', [PostController::class, 'update'])
    ->middleware(EnsureAbility::class.':'.Abilities::POST_UPDATE);
Route::delete('posts/{post}', [PostController::class, 'destroy'])
    ->middleware(EnsureAbility::class.':'.Abilities::POST_DELETE);

Route::post('posts/{post}/blocks', [BlockController::class, 'store'])
    ->middleware(EnsureAbility::class.':'.Abilities::BLOCK_MANAGE);
Route::post('posts/{post}/blocks/reorder', [BlockController::class, 'reorder'])
    ->middleware(EnsureAbility::class.':'.Abilities::BLOCK_MANAGE);
Route::put('blocks/{block}', [BlockController::class, 'update'])
    ->middleware(EnsureAbility::class.':'.Abilities::BLOCK_MANAGE);
Route::delete('blocks/{block}', [BlockController::class, 'destroy'])
    ->middleware(EnsureAbility::class.':'.Abilities::BLOCK_MANAGE);

Route::post('media', [MediaController::class, 'store'])
    ->middleware(EnsureAbility::class.':'.Abilities::MEDIA_UPLOAD);
Route::delete('media/{media}', [MediaController::class, 'destroy'])
    ->middleware(EnsureAbility::class.':'.Abilities::MEDIA_DELETE);
