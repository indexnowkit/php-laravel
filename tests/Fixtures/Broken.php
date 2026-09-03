<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Laravel\Eloquent\IndexNowable;

/** The rule reads an attribute the model does not have: the resolver must fail without breaking the save. */
#[IndexNow(route: 'pages.show', params: ['slug' => 'missingProperty'])]
final class Broken extends Model
{
    use IndexNowable;

    protected $table = 'broken';
    protected $guarded = [];
    public $timestamps = false;
}
