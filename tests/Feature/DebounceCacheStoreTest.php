<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\Psr16DebounceStore;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class DebounceCacheStoreTest extends LaravelTestCase
{
    protected function configOverrides(): array
    {
        return ['debounce' => ['per_url' => 600, 'store' => 'array', 'key_prefix' => 'inx_']];
    }

    #[TestDox('debounce.store names a cache store: the window is shared through Psr16DebounceStore and check reports it')]
    public function testCacheStore(): void
    {
        self::assertInstanceOf(Psr16DebounceStore::class, $this->app->make(DebounceStoreInterface::class));

        $this->kit()->submit(['/a']);
        $this->kit()->submit(['/a', '/b']);

        self::assertCount(2, $this->transport->posts);
        self::assertSame(['https://www.example.com/b'], $this->transport->posts[1]['body']['urlList'], 'the repeated URL was debounced through the cache');
        self::assertTrue($this->app->make('cache')->store('array')->has('inx_' . sha1('https://www.example.com/a')));

        $this->artisan('indexnow:submit', ['urls' => ['/a'], '--force' => true])->assertExitCode(0);
        self::assertCount(3, $this->transport->posts, '--force bypasses the window');
    }
}
