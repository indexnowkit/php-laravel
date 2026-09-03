<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Conformance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Laravel\Tests\Fixtures\BadAttribute;
use IndexNowKit\Laravel\Tests\Fixtures\Broken;
use IndexNowKit\Laravel\Tests\Fixtures\CategorizedPost;
use IndexNowKit\Laravel\Tests\Fixtures\Category;
use IndexNowKit\Laravel\Tests\Fixtures\MultiPost;
use IndexNowKit\Laravel\Tests\Fixtures\Post;
use IndexNowKit\Laravel\Tests\Fixtures\Tag;
use IndexNowKit\Laravel\Tests\Fixtures\Untracked;
use IndexNowKit\Laravel\Tests\Support\Harness;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\Conformance\OrmConformanceTestCase;
use IndexNowKit\Testing\FakeTransport;

/**
 * The core ORM conformance kit (A01-A21) driven through Eloquent: observer + Connection::afterCommit() for commit
 * safety, DB::transaction nesting through savepoints, `$touches` for the pivot scenario.
 */
final class OrmConformanceTest extends OrmConformanceTestCase
{
    private LaravelApplication $app;
    private FakeTransport $transport;
    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->logger = new ArrayLogger();
        $this->app = Harness::create($this->transport, $this->logger);
    }

    protected function tearDown(): void
    {
        Harness::destroy($this->app);
    }

    protected function transport(): FakeTransport
    {
        return $this->transport;
    }

    protected function logger(): ArrayLogger
    {
        return $this->logger;
    }

    protected function flush(): void
    {
        $this->app->make(IndexNowKit::class)->flush();
    }

    protected function collectedCount(): int
    {
        return $this->app->make(IndexNowKit::class)->collector->count();
    }

    protected function begin(): void
    {
        DB::beginTransaction();
    }

    protected function commit(): void
    {
        DB::commit();
    }

    protected function rollback(): void
    {
        DB::rollBack();
    }

    protected function createPost(string $slug, bool $published = true): object
    {
        return Post::query()->create(['slug' => $slug, 'published' => $published]);
    }

    protected function createMultiPost(string $slug, bool $published, bool $amp): object
    {
        return MultiPost::query()->create(['slug' => $slug, 'published' => $published, 'amp' => $amp]);
    }

    protected function createCategory(string $slug): object
    {
        return Category::query()->create(['slug' => $slug]);
    }

    protected function createCategorizedPost(string $slug, ?object $category = null): object
    {
        return CategorizedPost::query()->create(['slug' => $slug, 'category_id' => $category instanceof Category ? $category->id : null]);
    }

    protected function createTag(string $name): object
    {
        return Tag::query()->create(['name' => $name]);
    }

    protected function createUntracked(): object
    {
        return Untracked::query()->create(['name' => 'x']);
    }

    protected function createBroken(): object
    {
        return Broken::query()->create(['name' => 'x']);
    }

    protected function createBadAttribute(): object
    {
        return BadAttribute::query()->create(['name' => 'x']);
    }

    protected function update(object $model, array $fields): void
    {
        \assert($model instanceof Model);
        $model->fill($fields)->save();
    }

    protected function delete(object $model): void
    {
        \assert($model instanceof Model);
        $model->delete();
    }

    protected function attachTag(object $post, object $tag): void
    {
        \assert($post instanceof CategorizedPost && $tag instanceof Tag);
        // Tag::$touches updates the post's updated_at; within the same second Eloquent sees nothing dirty and fires no event.
        Carbon::setTestNow(Carbon::now()->addSeconds(5));
        $post->tags()->attach($tag);
    }

    protected function bulkUpdateTitle(string $title): void
    {
        Post::query()->update(['title' => $title]);
    }
}
