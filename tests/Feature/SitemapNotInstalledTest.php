<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use IndexNowKit\Config;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Laravel\IndexNowKitServiceProvider;
use IndexNowKit\Laravel\Sitemap\SitemapSupport;
use IndexNowKit\Laravel\Tests\Fixtures\Post;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * indexnowkit/sitemap not installed (the predicate forced to false): `indexnow:sitemap` is a stub that says what to
 * install and exits 1, `indexnow:check` prints one line about it, the `sitemap` block of the fixtures (which
 * differs from the package defaults) warns about nothing, no sitemap binding exists, everything else works and
 * nothing is logged.
 */
final class SitemapNotInstalledTest extends LaravelTestCase
{
    protected function setUp(): void
    {
        SitemapSupport::$installed = false;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        SitemapSupport::$installed = null;
        parent::tearDown();
    }

    protected function configOverrides(): array
    {
        return ['sitemap' => ['spol' => 'disk']]; // a typo the package would warn about: ignored without it
    }

    #[TestDox('indexnow:sitemap accepts the arguments of the real command, prints the install line and exits 1')]
    public function testStubCommand(): void
    {
        [$code, $output] = $this->artisanCall('indexnow:sitemap', ['sitemap' => 'https://www.example.com/sitemap.xml', '--dry-run' => true, '--changed-since' => '1 day']);

        self::assertSame(ExitCode::FAILURE, $code);
        self::assertStringContainsString(SitemapSupport::NOT_INSTALLED, $output);
        self::assertSame([], $this->transport->posts);
    }

    #[TestDox('indexnow:check says the block is ignored (the fixtures changed it); the other commands work')]
    public function testCheckAndOtherCommands(): void
    {
        [, $output] = $this->artisanCall('indexnow:check');
        self::assertStringContainsString(SitemapSupport::CHECK_MISSING_BLOCK_IGNORED, $output);
        self::assertStringNotContainsString('spool', $output, 'no spool line, no unknown option line');

        [$code, $output] = $this->artisanCall('indexnow:submit', ['urls' => ['/a'], '--dry-run' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        self::assertStringContainsString('dry_run', $output);
        self::assertArrayHasKey('indexnow:key:generate', $this->app->make(Kernel::class)->all());
    }

    #[TestDox('no sitemap binding exists; the config with the sitemap block builds without a warning; hooks work; nothing is logged')]
    public function testSilentWithoutThePackage(): void
    {
        self::assertFalse($this->app->bound(\IndexNowKit\Sitemap\SitemapSourceInterface::class));
        self::assertFalse($this->app->bound(\IndexNowKit\Sitemap\SitemapConfig::class));
        self::assertTrue($this->app->bound(IndexNowKitServiceProvider::SITEMAP_MISSING_CHECK));

        self::assertTrue($this->app->make(Config::class)->enabled);
        Post::query()->create(['slug' => 'silent']);
        $this->kit()->flush();
        self::assertSame(['https://www.example.com/posts/silent'], $this->sentUrls());

        self::assertSame([], $this->logger->messages('warning'), 'the sitemap block (with a typo) is ignored as a whole');
        self::assertSame([], $this->logger->messages('critical'));
        self::assertSame([], $this->logger->messages('error'));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function artisanCall(string $command, array $arguments = []): array
    {
        $output = new BufferedOutput();
        $code = $this->app->make(Kernel::class)->call($command, $arguments, $output);

        return [$code, $output->fetch()];
    }
}
