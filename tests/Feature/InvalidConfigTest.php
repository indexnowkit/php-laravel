<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use IndexNowKit\Config;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Dispatch\NullDispatcher;
use IndexNowKit\Laravel\Tests\Fixtures\Post;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class InvalidConfigTest extends LaravelTestCase
{
    protected function configOverrides(): array
    {
        return ['key' => 'short', 'unknown_option' => 1, 'debounce' => ['per_urls' => 5]];
    }

    #[TestDox('an invalid runtime config disables IndexNow with one critical log line instead of throwing from a save; typos are warned about')]
    public function testDisabledWithCriticalLog(): void
    {
        $config = $this->app->make(Config::class);
        self::assertFalse($config->enabled);
        self::assertTrue($config->dryRun);
        self::assertInstanceOf(NullDispatcher::class, $this->app->make(DispatcherInterface::class));

        Post::query()->create(['slug' => 'ignored']);
        $this->kit()->flush();

        self::assertSame([], $this->transport->posts);
        self::assertStringContainsString('IndexNow is disabled until it is fixed', implode("\n", $this->logger->messages('critical')));
        $warnings = implode("\n", $this->logger->messages('warning'));
        self::assertStringContainsString('unknown option(s)', $warnings);
        self::assertStringContainsString('unknown_option', $warnings);
        self::assertStringContainsString('debounce.per_urls', $warnings);
    }

    #[TestDox('indexnow:check prints the exact configuration error and exits 1')]
    public function testCheckReportsError(): void
    {
        $this->artisan('indexnow:check')
            ->expectsOutputToContain('configuration:')
            ->expectsOutputToContain('IndexNow is disabled until the configuration is fixed')
            ->assertExitCode(1);
    }
}
