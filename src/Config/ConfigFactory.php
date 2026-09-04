<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Config;

use IndexNowKit\Adapter\ConfigFactory as CoreConfigFactory;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Sitemap\SitemapConfig;
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
     * Keys this package owns on top of Config::OPTIONS and SitemapConfig::OPTIONS, dotted-path form only: a bare
     * block name in this list would stop unknownOptions() from checking the keys inside the block.
     */
    public const LARAVEL_OPTIONS = [
        'queue.connection', 'queue.queue', 'queue.delay',
        'key_file.path', 'key_file.host', 'key_file.route_name', 'key_file.middleware',
        'router.locales', 'router.locale_parameter', 'router.set_app_locale',
        'eloquent.enabled',
        'logging.channel',
    ];

    public const DISPATCH_MODES = ['queue', 'sync', 'none'];

    public static function factory(): CoreConfigFactory
    {
        return new CoreConfigFactory(
            ownedOptions: [...self::LARAVEL_OPTIONS, ...SitemapConfig::OPTIONS],
            dispatchModes: self::DISPATCH_MODES,
            needBaseUrl: ['queue'],
            checkCommand: 'php artisan indexnow:check',
        );
    }

    /**
     * Runtime path: never throws.
     *
     * @param array<string, mixed> $config the `indexnow` config array
     */
    public static function create(array $config, string $environment, ?LoggerInterface $logger = null): Config
    {
        return self::factory()->load($config, $environment, $logger ?? new NullLogger());
    }

    /**
     * Strict path (`indexnow:check`, tests).
     *
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationException
     */
    public static function build(array $config, string $environment): Config
    {
        return self::factory()->build($config, $environment);
    }
}
