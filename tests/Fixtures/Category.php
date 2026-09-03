<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Laravel\Eloquent\IndexNowable;

/**
 * @property int    $id
 * @property string $slug
 */
#[IndexNow(route: 'categories.show', params: ['category' => 'self'])]
final class Category extends Model
{
    use IndexNowable;

    protected $table = 'categories';
    protected $guarded = [];
    public $timestamps = false;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
