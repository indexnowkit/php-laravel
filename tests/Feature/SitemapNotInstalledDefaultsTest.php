<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use IndexNowKit\Laravel\Sitemap\SitemapSupport;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * indexnowkit/sitemap not installed and the `sitemap` block left as the package ships it: `check` prints the plain
 * line, not "the block is ignored".
 */
final class SitemapNotInstalledDefaultsTest extends LaravelTestCase
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
        /** @var array{sitemap: array<string, mixed>} $defaults */
        $defaults = require \dirname(__DIR__, 2) . '/config/indexnow.php';

        return ['sitemap' => $defaults['sitemap']];
    }

    #[TestDox('indexnow:check prints "sitemap: not installed (composer require indexnowkit/sitemap)"')]
    public function testCheckLine(): void
    {
        $output = new BufferedOutput();
        $this->app->make(Kernel::class)->call('indexnow:check', [], $output);

        $display = $output->fetch();
        self::assertStringContainsString(SitemapSupport::CHECK_MISSING, $display);
        self::assertStringNotContainsString('block in the configuration is ignored', $display);
    }
}
