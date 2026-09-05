<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Readme;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};
use IndexNowKit\Laravel\Eloquent\IndexNowable;

#[IndexNowDefaults(when: 'isPublished', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'posts.show', params: ['post' => 'self'])]                 // route model binding
#[IndexNow(route: 'posts.amp', params: ['slug' => 'slug'], when: 'hasAmp')]
#[IndexNow(via: 'category')]      // a changed post also refreshes its category page
#[IndexNow(urls: ['/'])]          // and the homepage
class Post extends Model
{
    use IndexNowable;

    protected $casts = ['published' => 'bool', 'amp' => 'bool'];

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function hasAmp(): bool
    {
        return $this->amp;
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
