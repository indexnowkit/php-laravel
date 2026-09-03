<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $name
 */
final class Tag extends Model
{
    protected $table = 'tags';
    protected $guarded = [];
    public $timestamps = false;

    /** Attaching a tag touches the posts, which is how a pivot change reaches the observer (conformance A20). */
    protected $touches = ['categorizedPosts'];

    /** @return BelongsToMany<CategorizedPost, $this> */
    public function categorizedPosts(): BelongsToMany
    {
        return $this->belongsToMany(CategorizedPost::class, 'categorized_post_tags', 'tag_id', 'post_id');
    }
}
