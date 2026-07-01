<?php

declare(strict_types=1);

namespace Aristonis\BlogManager\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimal host "author" model used to prove the configurable, decoupled
 * author relation. Not shipped with the package.
 */
final class User extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
