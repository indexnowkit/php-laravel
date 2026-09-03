<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Laravel\Eloquent\IndexNowable;

/**
 * A post that resubmits its category's page (`via`) and whose tags (pivot) trigger an update through `$touches`
 * on the Tag side, since pivot changes fire no model events on the owner.
 *
 * @property int      $id
 * @property string   $slug
 * @property int      $views
 * @property ?int     $category_id
 * @property Category $category
 */
#[IndexNow(route: 'pages.show', params: ['slug' => 'slug'])]
#[IndexNow(via: 'category')]
final class CategorizedPost extends Model
{
    use IndexNowable;

    protected $table = 'categorized_posts';
    protected $guarded = [];
    protected $casts = ['views' => 'int'];

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'categorized_post_tags', 'post_id', 'tag_id');
    }
}
