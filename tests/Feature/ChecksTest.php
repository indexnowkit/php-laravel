<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Config\Repository;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Laravel\Check\CacheStoreCheck;
use IndexNowKit\Laravel\Check\QueueCheck;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Sitemap\Check\SitemapSpoolCheck;
use IndexNowKit\Sitemap\SitemapConfig;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The adapter-specific lines of `indexnow:check`: queue wiring, debounce cache store, sitemap spool.
 */
final class ChecksTest extends LaravelTestCase
{
    #[TestDox('queue: dispatch queue needs an existing connection; sync driver warns; a real driver is ok; other dispatch modes are described')]
    public function testQueueCheck(): void
    {
        $config = $this->app->make(Repository::class);
        $check = new QueueCheck($config);

        $config->set('indexnow.dispatch', 'queue');
        $config->set('indexnow.queue.connection', 'nope');
        self::assertSame([CheckLevel::Error], $this->levels($check));
        self::assertStringContainsString('connection "nope" is not defined', $this->messages($check)[0]);

        $config->set('indexnow.queue.connection', 'sync');
        self::assertSame([CheckLevel::Warning], $this->levels($check));

        $config->set('queue.connections.redis', ['driver' => 'redis']);
        $config->set('indexnow.queue.connection', 'redis');
        $config->set('indexnow.queue.queue', 'seo');
        self::assertSame([CheckLevel::Ok], $this->levels($check));
        self::assertStringContainsString('connection "redis" (redis), queue "seo"', $this->messages($check)[0]);

        $config->set('indexnow.queue.connection', null);
        $config->set('queue.default', 'redis');
        self::assertStringContainsString('connection "redis"', $this->messages($check)[0]);

        $config->set('indexnow.dispatch', 'none');
        self::assertStringContainsString('never sent', $this->messages($check)[0]);
        $config->set('indexnow.dispatch', 'sync');
        self::assertStringContainsString('synchronously', $this->messages($check)[0]);
    }

    #[TestDox('debounce: off, memory, none, a usable store and an unusable store each get their line')]
    public function testCacheStoreCheck(): void
    {
        $config = $this->app->make(Repository::class);
        $check = new CacheStoreCheck($config, $this->app->make(Factory::class));

        $config->set('indexnow.debounce.per_url', 0);
        self::assertStringContainsString('off (debounce.per_url = 0)', $this->messages($check)[0]);

        $config->set('indexnow.debounce.per_url', 600);
        $config->set('indexnow.debounce.store', 'memory');
        self::assertSame([CheckLevel::Warning], $this->levels($check));

        $config->set('indexnow.debounce.store', 'none');
        self::assertStringContainsString('off (debounce.store = none)', $this->messages($check)[0]);

        $config->set('indexnow.debounce.store', 'cache');
        self::assertSame([CheckLevel::Ok], $this->levels($check));
        self::assertStringContainsString('600s per URL, shared through cache store', $this->messages($check)[0]);

        $config->set('indexnow.debounce.store', 'array');
        self::assertStringContainsString('cache store "array"', $this->messages($check)[0]);

        $config->set('indexnow.debounce.store', 'missing-store');
        self::assertSame([CheckLevel::Error], $this->levels($check));
        self::assertStringContainsString('is not usable', $this->messages($check)[0]);
    }

    #[TestDox('sitemap spool: disabled prints nothing; memory, writable disk, unwritable disk with auto/disk')]
    public function testSitemapSpoolCheck(): void
    {
        self::assertSame([], $this->levels(new SitemapSpoolCheck(SitemapConfig::disabled())));

        $check = new SitemapSpoolCheck(SitemapConfig::fromArray(['spool' => 'memory', 'max_bytes' => 1024]));
        self::assertStringContainsString('in memory (sitemap.spool: memory, at most 1 KiB', $this->messages($check)[0]);

        self::assertSame([CheckLevel::Ok], $this->levels(new SitemapSpoolCheck(SitemapConfig::fromArray(['spool' => 'auto', 'spool_dir' => sys_get_temp_dir()]))));

        self::assertSame([CheckLevel::Warning], $this->levels(new SitemapSpoolCheck(SitemapConfig::fromArray(['spool' => 'auto', 'spool_dir' => '/nonexistent/indexnow']))));
        $check = new SitemapSpoolCheck(SitemapConfig::fromArray(['spool' => 'disk', 'spool_dir' => '/nonexistent/indexnow']));
        self::assertSame([CheckLevel::Error], $this->levels($check));
        self::assertStringContainsString('does not exist', $this->messages($check)[0]);
    }

    /**
     * @return list<CheckLevel>
     */
    private function levels(QueueCheck|CacheStoreCheck|SitemapSpoolCheck $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): CheckLevel => $item->level, $report->items());
    }

    /**
     * @return list<string>
     */
    private function messages(QueueCheck|CacheStoreCheck|SitemapSpoolCheck $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): string => $item->message, $report->items());
    }
}
