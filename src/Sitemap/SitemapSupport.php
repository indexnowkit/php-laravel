<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Sitemap;

/**
 * Whether the optional `indexnowkit/sitemap` package is installed: the one predicate the provider, the config
 * factory and the commands share. Everything that reads `IndexNowKit\Sitemap\*` ({@see SitemapServices},
 * `Console\SitemapCommand`) is instantiated only when it holds; without the package `indexnow:sitemap` is
 * `Console\SitemapNotInstalledCommand`, `indexnow:check` prints one line and nothing is logged.
 */
final class SitemapSupport
{
    /** What the stub command prints without the package. */
    public const NOT_INSTALLED = 'indexnowkit/sitemap is not installed: composer require indexnowkit/sitemap';
    /** What `indexnow:check` prints without the package: the package's defaults in the config, or a block the application changed. */
    public const CHECK_MISSING = 'sitemap: not installed (composer require indexnowkit/sitemap)';
    public const CHECK_MISSING_BLOCK_IGNORED = 'sitemap: not installed, the sitemap block in the configuration is ignored (composer require indexnowkit/sitemap)';

    /**
     * @internal tests only: force the answer (false = boot as if the package were absent); null = detect
     */
    public static ?bool $installed = null;

    public static function installed(): bool
    {
        return self::$installed ?? class_exists(\IndexNowKit\Sitemap\SitemapReader::class);
    }

    /**
     * The `check` line without the package. The package's own config/indexnow.php always carries a `sitemap` block,
     * so only a block that differs from those defaults counts as "the application configured the sitemap".
     *
     * @param array<string, mixed> $block    the `sitemap` block of the merged config
     * @param array<string, mixed> $defaults the `sitemap` block of the package's config/indexnow.php
     */
    public static function checkLine(array $block, array $defaults): string
    {
        ksort($block);
        ksort($defaults);

        return $block === [] || $block === $defaults ? self::CHECK_MISSING : self::CHECK_MISSING_BLOCK_IGNORED;
    }
}
