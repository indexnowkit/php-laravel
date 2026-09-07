<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use IndexNowKit\Laravel\IndexNowKitServiceProvider;
use IndexNowKit\Laravel\Sitemap\SitemapServices;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * indexnowkit/sitemap not installed and the `sitemap` block left as the package ships it: `check` prints the plain
 * line, not "the block is ignored".
 */
final class SitemapNotInstalledDefaultsTest extends LaravelTestCase
{
    protected function overrideApplicationBindings($app): array
    {
        // Bound eagerly (not as a factory Closure): Testbench's own return type for this method is
        // array<string, string>, so the value must be pre-computed and set directly on $app instead.
        $app->instance(IndexNowKitServiceProvider::SITEMAP_PACKAGE, SitemapServices::package(false));

        return [];
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
        self::assertStringContainsString('sitemap: not installed (composer require indexnowkit/sitemap)', $display);
        self::assertStringNotContainsString('block in the configuration is ignored', $display);
    }
}
