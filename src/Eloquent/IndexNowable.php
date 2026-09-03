<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Eloquent;

/**
 * Registers the IndexNow observer on the model (Eloquent boot convention): changes of the model are submitted
 * according to its #[IndexNow] rules, after the surrounding transaction commits.
 *
 *   #[IndexNow(route: 'posts.show', params: ['post' => 'self'], when: 'isPublished')]
 *   class Post extends Model { use IndexNowable; }
 */
trait IndexNowable
{
    public static function bootIndexNowable(): void
    {
        static::observe(IndexNowObserver::class);
    }
}
