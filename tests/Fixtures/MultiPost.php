<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\IndexNowDefaults;
use IndexNowKit\Laravel\Eloquent\IndexNowable;

/**
 * Multi-rule model: the page, an AMP variant that additionally requires hasAmp(), and the homepage. `isPublished()`
 * is a method over the `published` attribute, exercising UrlRule::fieldCandidates() on Eloquent change sets.
 *
 * @property string $slug
 * @property bool   $published
 * @property bool   $amp
 */
#[IndexNowDefaults(when: 'isPublished')]
#[IndexNow(route: 'pages.show', params: ['slug' => 'slug'])]
#[IndexNow(route: 'posts.amp', params: ['slug' => 'slug'], when: 'hasAmp')]
#[IndexNow(urls: ['/'])]
final class MultiPost extends Model
{
    use IndexNowable;

    protected $table = 'multi_posts';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['published' => 'bool', 'amp' => 'bool'];

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function hasAmp(): bool
    {
        return $this->amp;
    }
}
