<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Unit;

use IndexNowKit\Laravel\Eloquent\IndexNowObserver;
use IndexNowKit\Laravel\Eloquent\RouteBindingFieldsInterface;
use IndexNowKit\Laravel\Tests\Fixtures\Post;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The Eloquent layer asks the router only through RouteBindingFieldsInterface, so it can run over a bare
 * illuminate/database (or a custom bridge) without illuminate/routing.
 */
final class IndexNowObserverSelfFieldsTest extends LaravelTestCase
{
    #[TestDox('a custom RouteBindingFieldsInterface decides which changed field renames the page; without one only the model route key does')]
    public function testBindingFieldSourceIsPluggable(): void
    {
        $post = Post::query()->create(['slug' => 'before', 'title' => 'T']);
        $this->kit()->flush();
        $this->transport->posts = [];

        $titleBound = new class implements RouteBindingFieldsInterface {
            public function bindingFieldFor(string $route, string $param): ?string
            {
                return 'title';
            }
        };
        $observer = new IndexNowObserver($this->kit(), router: $titleBound);
        $post->title = 'Renamed';
        $post->syncChanges();
        $observer->updated($post);
        $this->kit()->flush();
        $urls = $this->sentUrls();
        self::assertContains('https://www.example.com/posts/before', $urls, 'title is the binding field for this bridge, so a title change is a rename and the old URL is announced');
        $post->syncOriginal();

        $this->transport->posts = [];
        $noRouter = new IndexNowObserver($this->kit());
        $post->slug = 'after';
        $post->syncChanges();
        $noRouter->updated($post);
        $this->kit()->flush();
        $urls = $this->sentUrls();
        self::assertContains('https://www.example.com/posts/after', $urls);
        self::assertNotContains('https://www.example.com/posts/before', $urls, 'without a bridge only the model route key (id) renames the page; the {post:slug} binding is unknown, so the slug change is a plain update');
    }
}
