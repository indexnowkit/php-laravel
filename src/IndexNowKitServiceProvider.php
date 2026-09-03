<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Attribute\RuleRegistry;
use IndexNowKit\Check\Checker;
use IndexNowKit\Check\CheckerInterface;
use IndexNowKit\Client;
use IndexNowKit\ClientInterface;
use IndexNowKit\Collector\Collector;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Debounce\NullDebounceStore;
use IndexNowKit\Debounce\Psr16DebounceStore;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Dispatch\NullDispatcher;
use IndexNowKit\Dispatch\SyncDispatcher;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\LazyTransport;
use IndexNowKit\Http\Psr18Transport;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Laravel\Check\CacheStoreCheck;
use IndexNowKit\Laravel\Check\QueueCheck;
use IndexNowKit\Laravel\Check\SitemapSpoolCheck;
use IndexNowKit\Laravel\Config\ConfigFactory;
use IndexNowKit\Laravel\Console\CheckCommand;
use IndexNowKit\Laravel\Console\ExplainCommand;
use IndexNowKit\Laravel\Console\KeyGenerateCommand;
use IndexNowKit\Laravel\Console\SitemapCommand;
use IndexNowKit\Laravel\Console\SubmitCommand;
use IndexNowKit\Laravel\Console\SubmitModelCommand;
use IndexNowKit\Laravel\Eloquent\EloquentSubjectReader;
use IndexNowKit\Laravel\Eloquent\IndexNowObserver;
use IndexNowKit\Laravel\Http\KeyFileController;
use IndexNowKit\Laravel\Queue\QueueDispatcher;
use IndexNowKit\Laravel\Url\ContainerResolverLocator;
use IndexNowKit\Laravel\Url\LaravelRouteUrlResolver;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\SitemapSourceInterface;
use IndexNowKit\Sitemap\SpoolMode;
use IndexNowKit\Submitter;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Throttle\TokenBucket;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\ObjectChangeHandler;
use IndexNowKit\Url\ResolverLocatorInterface;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlNormalizer;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Url\UrlResolverInterface;
use Psr\Http\Client\ClientInterface as PsrClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Wires the core component graph into the container, one binding per core interface so an application can replace
 * any piece with `$this->app->bind()`. Registers the observer support, the key file route, the artisan commands and
 * the flush points (app()->terminating(), queue JobProcessed).
 */
final class IndexNowKitServiceProvider extends ServiceProvider
{
    /** Container id of the logger every IndexNow service writes to (bind an ArrayLogger in tests). */
    public const LOGGER = 'indexnowkit.logger';
    /** Container tag of extra Check\CheckInterface services printed by indexnow:check. */
    public const CHECK_TAG = 'indexnowkit.check';
    /** Publish tag of config/indexnow.php. */
    public const CONFIG_TAG = 'indexnow-config';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/indexnow.php', 'indexnow');
        ParamExtractor::registerReader(new EloquentSubjectReader());

        $this->app->singleton(self::LOGGER, static function (Container $app): LoggerInterface {
            $channel = self::block($app, 'logging')['channel'] ?? null;
            if (!$app->bound(LogManager::class) && !$app->bound('log')) {
                return new NullLogger();
            }
            $log = $app->make('log');

            return $log instanceof LogManager ? $log->channel(\is_string($channel) && $channel !== '' ? $channel : null) : ($log instanceof LoggerInterface ? $log : new NullLogger());
        });

        $this->registerCore();
        $this->registerUrls();
        $this->registerDispatch();
        $this->registerDiagnostics();

        $this->app->singleton(IndexNowManager::class, static fn(Container $app): IndexNowManager => new IndexNowManager($app->make(IndexNowKit::class), $app->make(RuleRegistry::class)));
        $this->app->singleton(IndexNowObserver::class, static fn(Container $app): IndexNowObserver => new IndexNowObserver(
            $app->make(IndexNowKit::class),
            $app->make(self::LOGGER),
            (bool) (self::block($app, 'eloquent')['enabled'] ?? true) && $app->make(Config::class)->enabled,
            $app->make(LaravelRouteUrlResolver::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/indexnow.php' => $this->app->configPath('indexnow.php')], self::CONFIG_TAG);
        if ($this->app->runningInConsole()) {
            $this->commands([KeyGenerateCommand::class, CheckCommand::class, SubmitCommand::class, SubmitModelCommand::class, ExplainCommand::class, SitemapCommand::class]);
        }
        $this->registerKeyFileRoute();

        // Flush points: end of the HTTP request / artisan command, and after every handled queue job.
        $this->app->terminating(function (): void {
            $this->flushIfCollected();
        });
        $this->app->make(EventDispatcher::class)->listen(JobProcessed::class, function (): void {
            $this->flushIfCollected();
        });
    }

    private function registerCore(): void
    {
        $this->app->singleton(Config::class, static fn(Container $app): Config => ConfigFactory::create(self::raw($app), (string) $app->make(Application::class)->environment(), $app->make(self::LOGGER)));
        $this->app->singleton(KeyProviderInterface::class, static fn(Container $app): KeyProviderInterface => StaticKeyProvider::fromConfig($app->make(Config::class)));
        $this->app->singleton(TransportInterface::class, static function (Container $app): TransportInterface {
            $client = self::block($app, 'http')['client'] ?? null;

            return new LazyTransport(static function () use ($app, $client): TransportInterface {
                $timeout = $app->make(Config::class)->httpTimeout;
                if (!\is_string($client) || $client === '') {
                    return Psr18Transport::discover(timeout: $timeout);
                }
                $instance = $app->make($client);
                if (!$instance instanceof PsrClient) {
                    throw new ConfigurationException(\sprintf('indexnow.http.client "%s" resolves to %s, which is not a PSR-18 client.', $client, get_debug_type($instance)));
                }

                return Psr18Transport::discover($instance, $timeout);
            });
        });
        $this->app->singleton(UrlNormalizerInterface::class, static fn(Container $app): UrlNormalizerInterface => new UrlNormalizer($app->make(Config::class)->baseUrl, $app->make(Config::class)->maxUrlLength));
        $this->app->singleton(ThrottleInterface::class, static fn(Container $app): ThrottleInterface => new TokenBucket($app->make(Config::class)->throttleMaxRequestsPerMinute, logger: $app->make(self::LOGGER)));
        $this->app->singleton(ClientInterface::class, static fn(Container $app): ClientInterface => new Client($app->make(TransportInterface::class), $app->make(KeyProviderInterface::class), $app->make(Config::class), $app->make(self::LOGGER), $app->make(ThrottleInterface::class), $app->make(UrlNormalizerInterface::class)));
        $this->app->singleton(DebounceStoreInterface::class, static function (Container $app): DebounceStoreInterface {
            $store = self::block($app, 'debounce')['store'] ?? 'cache';
            $store = \is_string($store) && $store !== '' ? $store : 'cache';

            return match ($store) {
                'memory' => new MemoryDebounceStore(),
                'none' => new NullDebounceStore(),
                default => new Psr16DebounceStore($app->make(CacheFactory::class)->store($store === 'cache' ? null : $store), $app->make(Config::class)->debounceKeyPrefix),
            };
        });
        $this->app->singleton(SubmitterInterface::class, static fn(Container $app): SubmitterInterface => new Submitter($app->make(ClientInterface::class), $app->make(Config::class), $app->make(DebounceStoreInterface::class), $app->make(self::LOGGER), $app->make(UrlNormalizerInterface::class)));
        $this->app->scoped(CollectorInterface::class, static fn(Container $app): CollectorInterface => new Collector($app->make(self::LOGGER), $app->make(Config::class)->collectorDetectLeaks, $app->make(Config::class)->logUrls));
        $this->app->singleton(KeyFileResponder::class, static fn(Container $app): KeyFileResponder => new KeyFileResponder($app->make(KeyProviderInterface::class), $app->make(Config::class)->serveKeyFile));
        $this->app->singleton(IndexNowKit::class, static fn(Container $app): IndexNowKit => new IndexNowKit(
            config: $app->make(Config::class),
            submitter: $app->make(SubmitterInterface::class),
            collector: $app->make(CollectorInterface::class),
            dispatcher: $app->make(DispatcherInterface::class),
            keys: $app->make(KeyProviderInterface::class),
            attributes: $app->make(AttributeReaderInterface::class),
            resolver: $app->make(GuardedUrlResolver::class),
            logger: $app->make(self::LOGGER),
            transport: $app->make(TransportInterface::class),
            sitemap: (self::block($app, 'sitemap')['enabled'] ?? true) === false ? null : $app->make(SitemapSourceInterface::class),
        ));
        $this->app->singleton(ObjectChangeHandler::class, static fn(Container $app): ObjectChangeHandler => $app->make(IndexNowKit::class)->changes());
    }

    private function registerUrls(): void
    {
        $this->app->singleton(RuleRegistry::class, static fn(): RuleRegistry => new RuleRegistry(new AttributeReader()));
        $this->app->alias(RuleRegistry::class, AttributeReaderInterface::class);
        $this->app->singleton(LaravelRouteUrlResolver::class, static function (Container $app): LaravelRouteUrlResolver {
            $router = self::raw($app)['router'] ?? [];
            $router = \is_array($router) ? $router : [];
            $locales = \is_array($router['locales'] ?? null) ? array_values(array_filter($router['locales'], 'is_string')) : [];
            $parameter = $router['locale_parameter'] ?? 'locale';

            return new LaravelRouteUrlResolver($app->make(UrlGenerator::class), $app->make(Router::class), $app->make(Config::class), $app->make(Application::class), $locales, \is_string($parameter) && $parameter !== '' ? $parameter : 'locale', (bool) ($router['set_app_locale'] ?? true));
        });
        $this->app->alias(LaravelRouteUrlResolver::class, RouteUrlResolverInterface::class);
        $this->app->singleton(ResolverLocatorInterface::class, static fn(Container $app): ResolverLocatorInterface => new ContainerResolverLocator($app));
        $this->app->singleton(UrlResolverInterface::class, static function (Container $app): UrlResolverInterface {
            $config = $app->make(Config::class);

            return new AttributeUrlResolver($app->make(AttributeReaderInterface::class), $app->make(RouteUrlResolverInterface::class), $app->make(ResolverLocatorInterface::class), $app->make(self::LOGGER), $config->resolverMaxViaDepth, $config->resolverMaxViaFanout, $config->localeHosts);
        });
        $this->app->singleton(GuardedUrlResolver::class, static fn(Container $app): GuardedUrlResolver => new GuardedUrlResolver($app->make(UrlResolverInterface::class), $app->make(AttributeReaderInterface::class), $app->make(self::LOGGER)));
    }

    private function registerDispatch(): void
    {
        $this->app->singleton(DispatcherInterface::class, static function (Container $app): DispatcherInterface {
            $config = $app->make(Config::class);
            if (!$config->enabled || $config->dispatch === 'none') {
                return new NullDispatcher();
            }
            if ($config->dispatch === 'queue') {
                $queue = self::raw($app)['queue'] ?? [];
                $queue = \is_array($queue) ? $queue : [];
                $connection = $queue['connection'] ?? null;
                $name = $queue['queue'] ?? null;
                $delay = $queue['delay'] ?? 0;

                return new QueueDispatcher($app->make(BusDispatcher::class), $config, $app->make(self::LOGGER), \is_string($connection) ? $connection : null, \is_string($name) ? $name : null, is_numeric($delay) ? (int) $delay : 0);
            }

            return new SyncDispatcher($app->make(SubmitterInterface::class), $app->make(self::LOGGER), $config->logUrls);
        });
    }

    private function registerDiagnostics(): void
    {
        $this->app->singleton(SitemapSourceInterface::class, static function (Container $app): SitemapSourceInterface {
            $sitemap = self::raw($app)['sitemap'] ?? [];
            $sitemap = \is_array($sitemap) ? $sitemap : [];
            $int = static fn(mixed $value, int $default): int => is_numeric($value) ? (int) $value : $default;
            $spool = SpoolMode::tryFrom(\is_string($sitemap['spool'] ?? null) ? $sitemap['spool'] : 'auto') ?? SpoolMode::Auto;
            $dir = $sitemap['spool_dir'] ?? null;

            return new SitemapReader(
                $app->make(TransportInterface::class),
                $int($sitemap['max_depth'] ?? null, 3),
                $app->make(self::LOGGER),
                $int($sitemap['max_sitemaps'] ?? null, SitemapReader::MAX_SITEMAPS),
                $int($sitemap['max_bytes'] ?? null, SitemapReader::MAX_XML_BYTES),
                (bool) ($sitemap['allow_foreign_hosts'] ?? false),
                $spool,
                \is_string($dir) && $dir !== '' ? $dir : null,
                $int($sitemap['fetch_retries'] ?? null, 2),
            );
        });
        $this->app->singleton(QueueCheck::class);
        $this->app->singleton(CacheStoreCheck::class);
        $this->app->singleton(SitemapSpoolCheck::class);
        $this->app->tag([QueueCheck::class, CacheStoreCheck::class, SitemapSpoolCheck::class], self::CHECK_TAG);
        $this->app->singleton(CheckerInterface::class, static fn(Container $app): CheckerInterface => new Checker($app->make(Config::class), $app->make(KeyProviderInterface::class), $app->make(TransportInterface::class), $app->tagged(self::CHECK_TAG)));
        $this->app->singleton(KeyFileController::class, static function (Container $app): KeyFileController {
            $keyFile = self::raw($app)['key_file'] ?? [];
            $maxAge = \is_array($keyFile) ? ($keyFile['cache_max_age'] ?? null) : null;

            return new KeyFileController($app->make(KeyFileResponder::class), is_numeric($maxAge) ? (int) $maxAge : KeyFileResponder::DEFAULT_MAX_AGE, $app->make(Config::class)->hosts !== []);
        });
    }

    private function registerKeyFileRoute(): void
    {
        $raw = self::raw($this->app);
        $keyFile = \is_array($raw['key_file'] ?? null) ? $raw['key_file'] : [];
        $enabled = \is_bool($raw['serve_key_file'] ?? null) ? $raw['serve_key_file'] : (bool) ($keyFile['enabled'] ?? true);
        if (!$enabled || ($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            return;
        }
        $path = \is_string($keyFile['path'] ?? null) && str_contains($keyFile['path'], '{key}') ? $keyFile['path'] : '/{key}.txt';
        $name = \is_string($keyFile['route_name'] ?? null) && $keyFile['route_name'] !== '' ? $keyFile['route_name'] : 'indexnow.key_file';
        $middleware = \is_array($keyFile['middleware'] ?? null) ? array_values(array_filter($keyFile['middleware'], 'is_string')) : [];
        $host = $keyFile['host'] ?? null;

        $route = $this->app->make(Router::class)->get($path, KeyFileController::class)->where('key', '[' . KeyValidator::ALPHABET . ']{' . KeyValidator::MIN_LENGTH . ',' . KeyValidator::MAX_LENGTH . '}')->name($name);
        if ($middleware !== []) {
            $route->middleware($middleware);
        }
        if (\is_string($host) && $host !== '') {
            $route->domain($host);
        }
    }

    /** Flush without building the facade for a request that collected nothing. */
    private function flushIfCollected(): void
    {
        if (!$this->app->resolved(CollectorInterface::class)) {
            return;
        }
        $collector = $this->app->make(CollectorInterface::class);
        if (!$collector->isEmpty()) {
            $this->app->make(IndexNowKit::class)->flush();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function raw(Container $app): array
    {
        $config = $app->make(Repository::class)->get('indexnow');

        return \is_array($config) ? $config : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function block(Container $app, string $name): array
    {
        $block = self::raw($app)[$name] ?? null;

        return \is_array($block) ? $block : [];
    }
}
