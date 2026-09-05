<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Config\Repository;
use IndexNowKit\Check\CheckerInterface;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Check\DebounceStoreCheck;
use IndexNowKit\Config;
use IndexNowKit\Laravel\Check\CacheStoreProbe;
use IndexNowKit\Laravel\Check\QueueCheck;
use IndexNowKit\Laravel\IndexNowKitServiceProvider;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Laravel\Tests\Support\Fixtures;
use IndexNowKit\Sitemap\Check\SitemapSpoolCheck;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Testing\Conformance\CheckOutputAssertions;
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

    #[TestDox('debounce: off, memory, none, a usable store and an unusable store each get their line (core DebounceStoreCheck + the Laravel cache probe)')]
    public function testDebounceStoreCheck(): void
    {
        $probe = $this->app->make(CacheStoreProbe::class)(...);
        $check = static fn(array $debounce): DebounceStoreCheck => new DebounceStoreCheck(Config::fromArray(['key' => Fixtures::KEY, 'debounce' => $debounce]), $probe, IndexNowKitServiceProvider::DEFAULT_DEBOUNCE_STORE);

        self::assertStringContainsString('off (debounce.per_url = 0)', $this->messages($check(['per_url' => 0]))[0]);
        self::assertSame([CheckLevel::Warning], $this->levels($check(['per_url' => 600, 'store' => 'memory'])));
        self::assertStringContainsString('no store (debounce.store = none)', $this->messages($check(['per_url' => 600, 'store' => 'none']))[0]);

        self::assertSame([CheckLevel::Ok], $this->levels($check(['per_url' => 600])), 'unset = the default cache store');
        self::assertStringContainsString('600s per URL, shared through cache store', $this->messages($check(['per_url' => 600]))[0]);
        self::assertStringContainsString('cache store "array"', $this->messages($check(['per_url' => 600, 'store' => 'array']))[0]);

        $failing = $check(['per_url' => 600, 'store' => 'missing-store']);
        self::assertSame([CheckLevel::Error], $this->levels($failing));
        self::assertStringContainsString('is not usable', $this->messages($failing)[0]);
        self::assertInstanceOf(DebounceStoreCheck::class, $this->app->make(DebounceStoreCheck::class), 'the provider binds the check with the probe');
    }

    #[TestDox('every line of the whole check, adapter checks included, carries a code (the API of check --json)')]
    public function testEveryCheckLineHasACode(): void
    {
        $report = $this->app->make(CheckerInterface::class)->run();

        CheckOutputAssertions::assertEveryItemHasCode($report, 'queue.dispatch', DebounceStoreCheck::CODE, 'eloquent.enabled', SitemapSpoolCheck::CODE, 'key_file.status');
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
    private function levels(CheckInterface $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): CheckLevel => $item->level, $report->items());
    }

    /**
     * @return list<string>
     */
    private function messages(CheckInterface $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): string => $item->message, $report->items());
    }
}
