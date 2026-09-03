<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Laravel\Eloquent\IndexNowable;

/**
 * @property string $slug
 */
#[IndexNow(route: 'pages.show', params: ['slug' => 'slug'])]
final class SoftPost extends Model
{
    use IndexNowable;
    use SoftDeletes;

    protected $table = 'soft_posts';
    protected $guarded = [];
}
