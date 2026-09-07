<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Sitemap;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Console\ResultFormatterInterface;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Laravel\Console\SitemapCommand;
use IndexNowKit\Laravel\IndexNowKitServiceProvider;
use IndexNowKit\Sitemap\Adapter\SitemapServices as Package;
use IndexNowKit\Sitemap\Check\SitemapSpoolCheck;
use IndexNowKit\Sitemap\Console\SitemapRunner;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapSourceInterface;

/**
 * The sitemap bindings: the container ids and the Laravel side (the config repository, the artisan command) over the
 * package's own wiring (`Sitemap\Adapter\SitemapServices`: the reader, the spool check, the runner). Called by the
 * provider when {@see package()} says the package is installed ({@see IndexNowKitServiceProvider::SITEMAP_PACKAGE}). An
 * application replaces `SitemapSourceInterface` to read from another place or format ([docs/extending.md]).
 */
final class SitemapServices
{
    /** Container id of the check `indexnow:check` prints for the spool. */
    public const SPOOL_CHECK = SitemapSpoolCheck::class;

    /**
     * The one predicate for `indexnowkit/sitemap`: the core's `OptionalPackage::sitemap()`, so it answers without the
     * package (the package's own `SitemapServices` cannot be loaded then); null = detect, false = wire as if the
     * package were absent (tests).
     */
    public static function package(?bool $installed = null): OptionalPackage
    {
        return OptionalPackage::sitemap($installed);
    }

    /**
     * The dotted keys of the `sitemap` block, for `Config\ConfigFactory` (`SitemapConfig::OPTIONS`).
     *
     * @return list<string>
     */
    public static function options(): array
    {
        return Package::options();
    }

    /**
     * @return list<class-string> the artisan command(s)
     */
    public static function commands(): array
    {
        return [SitemapCommand::class];
    }

    /**
     * @param string $logger container id of the PSR-3 logger
     */
    public static function register(Container $app, string $logger): void
    {
        // The validated `sitemap` block; a broken value disables the sitemap command with a critical log line, like the core options.
        $app->singleton(SitemapConfig::class, static fn(Container $app): SitemapConfig => Package::config(self::block($app), $app->make($logger), 'php artisan indexnow:check'));
        $app->singleton(SitemapSourceInterface::class, static fn(Container $app): SitemapSourceInterface => Package::reader($app->make(SitemapConfig::class), $app->make(TransportInterface::class), $app->make($logger)));
        $app->singleton(SitemapSpoolCheck::class, static fn(Container $app): SitemapSpoolCheck => Package::spoolCheck($app->make(SitemapConfig::class)));
        $app->singleton(SitemapRunner::class, static fn(Container $app): SitemapRunner => Package::runner(
            $app->make(IndexNowKit::class),
            $app->make(SitemapSourceInterface::class),
            $app->make(SubmitterFactoryInterface::class),
            $app->make(SitemapConfig::class),
            $app->make(ResultFormatterInterface::class),
            'indexnow.sitemap.url',
            $app->make(IndexNowKitServiceProvider::UNVERIFIED_SUBMITTER_FACTORY),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function block(Container $app): array
    {
        $block = $app->make(Repository::class)->get('indexnow.sitemap');

        return \is_array($block) ? $block : [];
    }
}
