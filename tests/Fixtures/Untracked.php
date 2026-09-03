<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use IndexNowKit\Laravel\Eloquent\IndexNowable;

/** Observed, but without any rule: nothing must ever be submitted for it. */
final class Untracked extends Model
{
    use IndexNowable;

    protected $table = 'untracked';
    protected $guarded = [];
    public $timestamps = false;
}
