<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Config;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the runtime Config from config/indexnow.php. Values come from env(), so they are only known at runtime:
 * instead of throwing from an observer or app()->terminating(), a broken value is logged once at critical and
 * IndexNow runs disabled until fixed. `php artisan indexnow:check` prints the exact error.
 */
final class ConfigFactory
{
    /** Blocks this package owns; everything else goes to the core. */
    public const LARAVEL_OPTIONS = [
        'queue', 'queue.connection', 'queue.queue', 'queue.delay',
        'key_file', 'key_file.enabled', 'key_file.path', 'key_file.host', 'key_file.cache_max_age', 'key_file.route_name', 'key_file.middleware',
        'router', 'router.locales', 'router.locale_parameter', 'router.set_app_locale',
        'eloquent', 'eloquent.enabled',
        'sitemap', 'sitemap.enabled', 'sitemap.url', 'sitemap.max_depth', 'sitemap.max_sitemaps', 'sitemap.max_bytes', 'sitemap.allow_foreign_hosts', 'sitemap.spool', 'sitemap.spool_dir', 'sitemap.fetch_retries',
        'logging.channel', 'debounce.store', 'http.client',
    ];

    /**
     * @param array<string, mixed> $config the `indexnow` config array
     */
    public static function create(array $config, string $environment, ?LoggerInterface $logger = null): Config
    {
        $logger ??= new NullLogger();
        try {
            $unknown = Config::unknownOptions($config, self::LARAVEL_OPTIONS);
            if ($unknown !== []) {
                $logger->warning('indexnow: unknown option(s) in config/indexnow.php: {options}', ['options' => implode(', ', $unknown)]);
            }

            return self::build($config, $environment);
        } catch (ConfigurationException $e) {
            $logger->critical('indexnow: invalid configuration, IndexNow is disabled until it is fixed: {error} (run "php artisan indexnow:check")', ['error' => $e->getMessage(), 'exception' => $e]);

            return new Config(enabled: false, dryRun: true, environment: $environment);
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationException
     */
    public static function build(array $config, string $environment): Config
    {
        $core = self::coreOptions($config);
        $core['environment'] ??= $environment;
        $built = Config::fromArray($core);
        if ($built->dispatch === 'queue' && $built->baseUrl === null) {
            throw new ConfigurationException('"dispatch" is "queue" but "base_url" is not set: a queue worker has no request to take the host from. Set INDEXNOW_BASE_URL (or APP_URL).');
        }
        if (!\in_array($built->dispatch, ['sync', 'queue', 'none'], true)) {
            throw new ConfigurationException(\sprintf('"dispatch" must be sync, queue or none, got "%s".', $built->dispatch));
        }

        return $built;
    }

    /**
     * Strips the Laravel-only blocks and maps the deprecated alias before handing the array to the core.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function coreOptions(array $config): array
    {
        $keyFile = \is_array($config['key_file'] ?? null) ? $config['key_file'] : [];
        $serve = $config['serve_key_file'] ?? null;
        $config['serve_key_file'] = \is_bool($serve) ? $serve : (bool) ($keyFile['enabled'] ?? true);
        unset($config['queue'], $config['key_file'], $config['router'], $config['eloquent'], $config['sitemap']);
        foreach (['logging' => 'channel', 'debounce' => 'store', 'http' => 'client'] as $block => $key) {
            if (\is_array($config[$block] ?? null)) {
                unset($config[$block][$key]);
                if ($config[$block] === []) {
                    unset($config[$block]);
                }
            }
        }
        if (\is_string($config['environment'] ?? null) && $config['environment'] === '') {
            unset($config['environment']);
        }

        return $config;
    }
}
