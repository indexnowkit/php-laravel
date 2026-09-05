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
use IndexNowKit\Sitemap\Check\SitemapSpoolCheck;
use IndexNowKit\Sitemap\Console\SitemapRunner;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\SitemapSourceInterface;

/**
 * The sitemap bindings: the only wiring of the package that reads `IndexNowKit\Sitemap\*`, called by the provider
 * when {@see package()} says the package is installed ({@see IndexNowKitServiceProvider::SITEMAP_PACKAGE}). An
 * application replaces `SitemapSourceInterface` to read from another place or format ([docs/extending.md]).
 */
final class SitemapServices
{
    /** Container id of the check `indexnow:check` prints for the spool. */
    public const SPOOL_CHECK = SitemapSpoolCheck::class;

    /**
     * The one predicate for `indexnowkit/sitemap` (safe to call without the package: `::class` on an absent class
     * is a string); null = detect, false = wire as if the package were absent (tests).
     */
    public static function package(?bool $installed = null): OptionalPackage
    {
        return new OptionalPackage('indexnowkit/sitemap', SitemapReader::class, 'sitemap', $installed);
    }

    /**
     * The dotted keys of the `sitemap` block, for `Config\ConfigFactory` (`SitemapConfig::OPTIONS`).
     *
     * @return list<string>
     */
    public static function options(): array
    {
        return SitemapConfig::OPTIONS;
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
        $app->singleton(SitemapConfig::class, static fn(Container $app): SitemapConfig => SitemapConfig::loadOrDisabled(self::block($app), $app->make($logger), 'php artisan indexnow:check'));
        $app->singleton(SitemapSourceInterface::class, static fn(Container $app): SitemapSourceInterface => SitemapReader::fromConfig($app->make(SitemapConfig::class), $app->make(TransportInterface::class), $app->make($logger)));
        $app->singleton(SitemapSpoolCheck::class, static fn(Container $app): SitemapSpoolCheck => new SitemapSpoolCheck($app->make(SitemapConfig::class)));
        $app->singleton(SitemapRunner::class, static fn(Container $app): SitemapRunner => new SitemapRunner(
            $app->make(IndexNowKit::class),
            $app->make(SitemapSourceInterface::class),
            $app->make(SubmitterFactoryInterface::class),
            $app->make(SitemapConfig::class)->url,
            $app->make(ResultFormatterInterface::class),
            sitemapUrlOption: 'indexnow.sitemap.url',
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
