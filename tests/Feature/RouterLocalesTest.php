<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Laravel\Facades\IndexNowKit;
use IndexNowKit\Laravel\Tests\Fixtures\PlainPost;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Url\RouteUrlResolverInterface;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * `locales: 'all'` with an empty `router.locales`: the rule collapses to one URL in the current locale, which used
 * to happen without a word anywhere. It is now one warning per process (and one `indexnow:check` line, see
 * `ChecksTest::testRouterLocalesCheck`).
 */
final class RouterLocalesTest extends LaravelTestCase
{
    protected function configOverrides(): array
    {
        return ['router' => ['locales' => []]];
    }

    #[TestDox('locales: all with an empty router.locales warns once and still resolves the current locale')]
    public function testEmptyLocaleListWarnsOnce(): void
    {
        IndexNowKit::observe(PlainPost::class, [new IndexNow(route: 'pages.show', params: ['slug' => 'slug'], locales: 'all')]);
        $first = PlainPost::query()->create(['slug' => 'one', 'published' => true]);
        $second = PlainPost::query()->create(['slug' => 'two', 'published' => true]);

        self::assertSame(['https://www.example.com/posts/one'], IndexNowKit::urlsFor($first), 'one URL, in the current locale');
        self::assertSame(['https://www.example.com/posts/two'], IndexNowKit::urlsFor($second));

        $warnings = array_values(array_filter($this->logger->messages('warning'), static fn(string $m): bool => str_contains($m, 'router.locales')));
        self::assertCount(1, $warnings, 'once per process, not once per model');
        self::assertStringContainsString('one URL in the current locale is generated instead of one per locale', $warnings[0]);
    }

    #[TestDox('a configured list is used without a warning')]
    public function testConfiguredListIsSilent(): void
    {
        $bridge = $this->app->make(RouteUrlResolverInterface::class);
        $bridge->locales('current');

        self::assertSame([], array_values(array_filter($this->logger->messages('warning'), static fn(string $m): bool => str_contains($m, 'router.locales'))));
    }
}
