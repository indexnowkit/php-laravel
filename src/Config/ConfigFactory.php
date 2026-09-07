<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Config;

use IndexNowKit\Adapter\ConfigFactory as CoreConfigFactory;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the runtime Config from config/indexnow.php: the core's `Adapter\ConfigFactory` declared for Laravel.
 * Values come from env(), so they are only known at runtime: instead of throwing from an observer or
 * app()->terminating(), a broken value is logged once at critical and IndexNow runs disabled until fixed.
 * `php artisan indexnow:check` prints the exact error.
 */
final class ConfigFactory
{
    /**
     * Keys this package owns on top of Config::OPTIONS (and SitemapConfig::OPTIONS when indexnowkit/sitemap is
     * installed), dotted-path form only: a bare block name in this list would stop unknownOptions() from checking
     * the keys inside the block.
     */
    public const LARAVEL_OPTIONS = [
        'queue.connection', 'queue.queue', 'queue.delay',
        'key_file.path', 'key_file.host', 'key_file.route_name', 'key_file.middleware',
        'router.locales', 'router.locale_parameter', 'router.set_app_locale',
        'eloquent.enabled',
        'logging.channel',
    ];

    public const DISPATCH_MODES = ['queue', 'sync', 'none'];

    /**
     * Without indexnowkit/sitemap the `sitemap` block is ignored as a whole (no "unknown option" warning for a
     * configuration written for the package); with it, its keys are owned and typos inside it are warned about.
     * The same for indexnowkit/verify and the `verify` block, indexnowkit/history and the `history` block.
     *
     * The predicates are the core's `OptionalPackage::sitemap()` / `verify()` / `history()`: they answer without the
     * package and know the options their package owns (`OptionalPackage::ownedOptions()`, loaded only behind the
     * predicate) and which block is ignored without it (`ignoredBlocks()`).
     *
     * @param bool|null $sitemapInstalled null = detect; the provider passes the answer of the container's
     *                                    `OptionalPackage`, tests pass false
     * @param bool|null $verifyInstalled  the same for `indexnowkit/verify`
     * @param bool|null $historyInstalled the same for `indexnowkit/history`
     */
    public static function factory(?bool $sitemapInstalled = null, ?bool $verifyInstalled = null, ?bool $historyInstalled = null): CoreConfigFactory
    {
        $packages = [OptionalPackage::sitemap($sitemapInstalled), OptionalPackage::verify($verifyInstalled), OptionalPackage::history($historyInstalled)];

        return new CoreConfigFactory(
            ownedOptions: [...self::LARAVEL_OPTIONS, ...OptionalPackage::ownedOptions($packages)],
            dispatchModes: self::DISPATCH_MODES,
            needBaseUrl: ['queue'],
            checkCommand: 'php artisan indexnow:check',
            ignoreBlocks: OptionalPackage::ignoredBlocks($packages),
        );
    }

    /**
     * Runtime path: never throws.
     *
     * @param array<string, mixed> $config the `indexnow` config array
     */
    public static function create(array $config, string $environment, ?LoggerInterface $logger = null, ?bool $sitemapInstalled = null, ?bool $verifyInstalled = null, ?bool $historyInstalled = null): Config
    {
        return self::factory($sitemapInstalled, $verifyInstalled, $historyInstalled)->load($config, $environment, $logger ?? new NullLogger());
    }

    /**
     * Strict path (`indexnow:check`, tests).
     *
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationException
     */
    public static function build(array $config, string $environment, ?bool $sitemapInstalled = null, ?bool $verifyInstalled = null, ?bool $historyInstalled = null): Config
    {
        return self::factory($sitemapInstalled, $verifyInstalled, $historyInstalled)->build($config, $environment);
    }
}
