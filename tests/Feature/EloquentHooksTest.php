<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Support\Facades\DB;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\IndexNowDefaults;
use IndexNowKit\Laravel\Facades\IndexNowKit;
use IndexNowKit\Laravel\Tests\Fixtures\PlainPost;
use IndexNowKit\Laravel\Tests\Fixtures\Post;
use IndexNowKit\Laravel\Tests\Fixtures\SoftPost;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Laravel-specific hook behaviour on top of the shared ORM conformance kit: soft deletes, runtime registration
 * without a trait, the facade, transactions through DB::transaction().
 */
final class EloquentHooksTest extends LaravelTestCase
{
    #[TestDox('SoftDeletes: soft delete = deleted, restore = created, force delete = deleted')]
    public function testSoftDeletes(): void
    {
        $post = SoftPost::query()->create(['slug' => 'soft']);
        $this->kit()->flush();
        self::assertSame(['https://www.example.com/posts/soft'], $this->sentUrls());

        $post->delete();
        $this->kit()->flush();
        self::assertCount(2, $this->transport->posts, 'soft delete: the page answers 404, announced as deletion');
        self::assertSame(['https://www.example.com/posts/soft'], $this->transport->posts[1]['body']['urlList']);

        $post->restore();
        $this->kit()->flush();
        self::assertCount(3, $this->transport->posts, 'restore: the page is back');

        $post->forceDelete();
        $this->kit()->flush();
        self::assertCount(4, $this->transport->posts);
        self::assertSame(0, SoftPost::withTrashed()->count());
    }

    #[TestDox('IndexNowKit::observe() hooks a model without trait or attribute through RuleRegistry rules')]
    public function testObserveWithoutTrait(): void
    {
        IndexNowKit::observe(PlainPost::class, [new IndexNow(route: 'pages.show', params: ['slug' => 'slug'])], new IndexNowDefaults(when: 'published'));

        $post = PlainPost::query()->create(['slug' => 'plain', 'published' => true]);
        PlainPost::query()->create(['slug' => 'plain-draft', 'published' => false]);
        $this->kit()->flush();
        self::assertSame(['https://www.example.com/posts/plain'], $this->sentUrls());

        $post->update(['published' => false]);
        $this->kit()->flush();
        self::assertCount(2, $this->transport->posts, 'unpublish through a registered rule is a deletion too');
        self::assertFalse(IndexNowKit::rules()->rules(PlainPost::class)->isEmpty(), 'the rules are registered');
    }

    #[TestDox('the facade submits models in bulk after a mass update (A13 manual path) and exposes the core kit')]
    public function testFacadeSubmitModels(): void
    {
        Post::query()->create(['slug' => 'm1']);
        Post::query()->create(['slug' => 'm2']);
        $this->kit()->flush();
        $this->transport->posts = [];

        Post::query()->update(['title' => 'bulk']);
        self::assertSame([], $this->transport->posts, 'a mass update fires no events');

        $results = IndexNowKit::submitModels(Post::query()->get());
        self::assertCount(1, $results);
        self::assertSame(['https://www.example.com/posts/m1', 'https://www.example.com/posts/m2'], $this->sentUrls());
        self::assertSame(['https://www.example.com/posts/m1'], IndexNowKit::urlsFor(Post::query()->firstOrFail()));
        self::assertCount(1, IndexNowKit::explain(Post::query()->firstOrFail()));
        self::assertSame($this->kit(), IndexNowKit::kit());

        IndexNowKit::submitModel(Post::query()->firstOrFail());
        IndexNowKit::collect(['/x']);
        IndexNowKit::flush();
        self::assertCount(3, $this->transport->posts);
    }

    #[TestDox('DB::transaction: URLs of every model saved inside leave once, after the closure committed')]
    public function testDbTransactionClosure(): void
    {
        DB::transaction(function (): void {
            Post::query()->create(['slug' => 't1']);
            Post::query()->create(['slug' => 't2']);
            self::assertSame(0, $this->kit()->collector->count(), 'nothing collected before COMMIT');
        });
        self::assertSame(2, $this->kit()->collector->count());
        $this->kit()->flush();

        self::assertCount(1, $this->transport->posts);
        self::assertEqualsCanonicalizing(['https://www.example.com/posts/t1', 'https://www.example.com/posts/t2'], $this->sentUrls());
    }

    #[TestDox('a rule reading a missing attribute logs an error and never throws into the save; the row is written')]
    public function testResolverErrorDoesNotBreakSave(): void
    {
        $broken = \IndexNowKit\Laravel\Tests\Fixtures\Broken::query()->create(['name' => 'x']);
        $this->kit()->flush();

        self::assertTrue($broken->exists);
        self::assertSame([], $this->transport->posts);
        self::assertStringContainsString('missingProperty', implode("\n", $this->logger->messages('error')));
    }
}
