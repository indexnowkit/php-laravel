<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Laravel\Eloquent\IndexNowable;

/**
 * The conformance "post": page at /posts/{post:slug} (route model binding through `self`), public while `published`.
 *
 * @property int    $id
 * @property string $slug
 * @property string $title
 * @property bool   $published
 * @property int    $views
 */
#[IndexNow(route: 'posts.show', params: ['post' => 'self'], when: 'published', fields: ['slug', 'title', 'published'])]
final class Post extends Model
{
    use IndexNowable;

    protected $table = 'posts';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['published' => 'bool', 'views' => 'int'];
    /** Eloquent does not read DB defaults back after insert: a `when` field needs a model default to be visible in `created`. */
    protected $attributes = ['title' => 'title', 'published' => true, 'views' => 0];
}
