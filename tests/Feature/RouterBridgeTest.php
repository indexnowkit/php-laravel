<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\IndexNowDefaults;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Laravel\Facades\IndexNowKit;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Laravel\Url\LaravelRouteUrlResolver;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlResolverInterface;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @property string $slug
 */
final class Article extends Model
{
    protected $table = 'posts';
    protected $guarded = [];
    public $timestamps = false;
}

final class UppercaseResolver implements UrlResolverInterface
{
    public function __construct(private readonly LaravelRouteUrlResolver $router) {}

    public function resolve(object $subject, \IndexNowKit\Event $event): array
    {
        \assert($subject instanceof Article);

        return [$this->router->generate('pages.show', ['slug' => strtoupper($subject->slug)])];
    }
}

final class RouterBridgeTest extends LaravelTestCase
{
    protected function configOverrides(): array
    {
        return ['hosts' => ['example.de' => self::SECOND_KEY, 'shop.example.com' => ['key' => self::KEY, 'base_url' => 'https://shop.example.com']], 'locale_hosts' => ['de' => 'example.de']];
    }

    private function bridge(): LaravelRouteUrlResolver
    {
        $bridge = $this->app->make(RouteUrlResolverInterface::class);
        \assert($bridge instanceof LaravelRouteUrlResolver);

        return $bridge;
    }

    #[TestDox('in the console URLs are rebased onto base_url; a pinned host uses its base_url or https://host')]
    public function testOrigin(): void
    {
        $bridge = $this->bridge();
        self::assertSame('https://www.example.com/posts/a', $bridge->generate('pages.show', ['slug' => 'a']));
        self::assertSame('https://example.de/posts/a', $bridge->generate('pages.show', ['slug' => 'a'], null, 'example.de'));
        self::assertSame('https://shop.example.com/posts/a', $bridge->generate('pages.show', ['slug' => 'a'], null, 'shop.example.com'));
    }

    #[TestDox('a route with its own domain keeps it')]
    public function testDomainRoute(): void
    {
        self::assertSame('http://shop.example.com/products/p', $this->bridge()->generate('products.show', ['slug' => 'p']));
    }

    #[TestDox('locales: current = [null]; all = router.locales; the locale goes into the route parameter only when the route declares it')]
    public function testLocales(): void
    {
        $bridge = $this->bridge();
        self::assertSame([null], $bridge->locales('current'));
        self::assertSame(['en', 'de'], $bridge->locales('all'));
        self::assertSame(['fr'], $bridge->locales(['fr']));
        self::assertSame([null], $bridge->locales([]));
        self::assertSame('https://www.example.com/de/articles/a', $bridge->generate('articles.show', ['slug' => 'a'], 'de'));
        self::assertSame('https://www.example.com/posts/a', $bridge->generate('pages.show', ['slug' => 'a'], 'de'), 'no locale parameter on the route: nothing appended, no query string');
        self::assertSame('en', $this->app->getLocale(), 'the application locale is restored');
    }

    #[TestDox('a rule with locales: all and locale_hosts generates each locale on its host')]
    public function testLocaleHosts(): void
    {
        IndexNowKit::observe(Article::class, [new IndexNow(route: 'articles.show', params: ['slug' => 'slug'], locales: 'all')]);
        $article = Article::query()->create(['slug' => 'multi']);

        self::assertSame(['https://www.example.com/en/articles/multi', 'https://example.de/de/articles/multi'], IndexNowKit::urlsFor($article));
    }

    #[TestDox('missing route or missing parameter -> ConfigurationException naming the route')]
    public function testErrors(): void
    {
        $bridge = $this->bridge();
        try {
            $bridge->generate('nope.show', []);
            self::fail('expected an exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('"nope.show"', $e->getMessage());
        }
        try {
            $bridge->generate('pages.show', []);
            self::fail('expected an exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('Missing required parameter', $e->getMessage());
        }
    }

    #[TestDox('bindingFieldFor reads {post:slug}; null for a default key or an unknown route')]
    public function testBindingField(): void
    {
        $bridge = $this->bridge();
        self::assertSame('slug', $bridge->bindingFieldFor('posts.show', 'post'));
        self::assertNull($bridge->bindingFieldFor('categories.show', 'category'));
        self::assertNull($bridge->bindingFieldFor('nope', 'x'));
    }

    #[TestDox('#[IndexNow(resolver: Class)] is built by the container with its dependencies')]
    public function testContainerResolver(): void
    {
        IndexNowKit::observe(Article::class, [new IndexNow(resolver: UppercaseResolver::class)], new IndexNowDefaults());
        $article = Article::query()->create(['slug' => 'loud']);

        self::assertSame(['https://www.example.com/posts/LOUD'], IndexNowKit::urlsFor($article));
    }

    #[TestDox('an unknown resolver id is a logged configuration error, not an exception')]
    public function testUnknownResolver(): void
    {
        IndexNowKit::observe(Article::class, [new IndexNow(resolver: 'nope.resolver')]);
        $article = Article::query()->create(['slug' => 'x']);

        self::assertSame([], IndexNowKit::urlsFor($article));
        self::assertStringContainsString('nope.resolver', implode("\n", $this->logger->messages('error')));
    }
}
