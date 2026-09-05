<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Adapter\SubmitterFactory;
use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Attribute\RuleRegistry;
use IndexNowKit\Check\Checker;
use IndexNowKit\Check\CheckerInterface;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\DebounceStoreCheck;
use IndexNowKit\Client;
use IndexNowKit\ClientInterface;
use IndexNowKit\Collector\Collector;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Config;
use IndexNowKit\Console\ResultFormatterInterface;
use IndexNowKit\Console\ResultRenderer;
use IndexNowKit\Console\SubjectLoaderInterface;
use IndexNowKit\Console\Vocabulary;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Dispatch\DispatcherFactory;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\TransportFactory;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Laravel\Check\CacheStoreProbe;
use IndexNowKit\Laravel\Check\EloquentCheck;
use IndexNowKit\Laravel\Check\QueueCheck;
use IndexNowKit\Laravel\Config\ConfigFactory;
use IndexNowKit\Laravel\Console\CheckCommand;
use IndexNowKit\Laravel\Console\ConfigCommand;
use IndexNowKit\Laravel\Console\ExplainCommand;
use IndexNowKit\Laravel\Console\KeyGenerateCommand;
use IndexNowKit\Laravel\Console\ModelLoader;
use IndexNowKit\Laravel\Console\SitemapNotInstalledCommand;
use IndexNowKit\Laravel\Console\SubmitCommand;
use IndexNowKit\Laravel\Console\SubmitModelCommand;
use IndexNowKit\Laravel\Eloquent\EloquentSubjectReader;
use IndexNowKit\Laravel\Eloquent\IndexNowObserver;
use IndexNowKit\Laravel\Eloquent\RouteBindingFieldsInterface;
use IndexNowKit\Laravel\Event\EventDispatcherBridge;
use IndexNowKit\Laravel\Http\KeyFileController;
use IndexNowKit\Laravel\Queue\QueueDispatcher;
use IndexNowKit\Laravel\Sitemap\SitemapServices;
use IndexNowKit\Laravel\Url\LaravelRouteUrlResolver;
use IndexNowKit\Submission\NullSubmissionStore;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\Submitter;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Throttle\TokenBucket;
use IndexNowKit\Url\ArrayResolverLocator;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\ObjectChangeHandler;
use IndexNowKit\Url\ResolverLocatorInterface;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlNormalizerFactory;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Url\UrlResolverInterface;
use IndexNowKit\Version;
use Psr\EventDispatcher\EventDispatcherInterface as Psr14;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface as Psr16;
use Throwable;

/**
 * Wires the core component graph into the container, one binding per core interface so an application can replace
 * any piece with `$this->app->bind()`; the bodies are the core's factories (`Http\TransportFactory`,
 * `Debounce\DebounceStoreFactory`, `Dispatch\DispatcherFactory`, the `fromConfig()` constructors). Registers the
 * observer support, the key file route, the artisan commands and the flush points (app()->terminating(), queue
 * JobProcessed). The sitemap bindings come from `Sitemap\SitemapServices` when the optional `indexnowkit/sitemap`
 * is installed (`Adapter\OptionalPackage` under {@see SITEMAP_PACKAGE}); without it `indexnow:sitemap` is a stub and
 * `indexnow:check` prints one line.
 */
final class IndexNowKitServiceProvider extends ServiceProvider
{
    /** Container id of the logger every IndexNow service writes to (bind an ArrayLogger in tests). */
    public const LOGGER = 'indexnowkit.logger';
    /** Container tag of extra Check\CheckInterface services printed by indexnow:check. */
    public const CHECK_TAG = 'indexnowkit.check';
    /** Publish tag of config/indexnow.php. */
    public const CONFIG_TAG = 'indexnow-config';
    /** The debounce store when `debounce.store` is unset: the application's default cache store. */
    public const DEFAULT_DEBOUNCE_STORE = 'cache';
    /**
     * Container id of the PSR-16 cache the 403 counter of `Client` lives in (`?Psr\SimpleCache\CacheInterface`): the
     * cache store behind `debounce.store`, null for `memory`/`none` (the counter then stays in the process).
     */
    public const FAILURE_CACHE = 'indexnowkit.failure_cache';
    /**
     * Container id of the PSR-14 dispatcher the submitter publishes every `Result` to: Laravel's event dispatcher
     * behind `Event\EventDispatcherBridge`, so `Event::listen(Result::class, …)` and Telescope see the results.
     */
    public const EVENTS = 'indexnowkit.events';
    /** Container id of the `check` line printed without `indexnowkit/sitemap`. */
    public const SITEMAP_MISSING_CHECK = 'indexnowkit.check.sitemap_missing';
    /**
     * Container id of the `Adapter\OptionalPackage` for `indexnowkit/sitemap`: the one predicate the provider, the
     * config factory and the commands share. Bind it before the provider registers to override it (Testbench:
     * `overrideApplicationBindings()` returning `[IndexNowKitServiceProvider::SITEMAP_PACKAGE => fn() =>
     * SitemapServices::package(false)]` boots as if the package were absent; `defineEnvironment()` is too late).
     */
    public const SITEMAP_PACKAGE = 'indexnowkit.sitemap_package';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/indexnow.php', 'indexnow');
        ParamExtractor::registerReader(new EloquentSubjectReader());
        if (!$this->app->bound(self::SITEMAP_PACKAGE)) {
            $this->app->instance(self::SITEMAP_PACKAGE, SitemapServices::package());
        }

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
            $app->make(RouteBindingFieldsInterface::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/indexnow.php' => $this->app->configPath('indexnow.php')], self::CONFIG_TAG);
        if ($this->app->runningInConsole()) {
            $this->registerAbout();
            $this->commands([KeyGenerateCommand::class, CheckCommand::class, ConfigCommand::class, SubmitCommand::class, SubmitModelCommand::class, ExplainCommand::class, ...$this->sitemapPackage()->installed() ? SitemapServices::commands() : [SitemapNotInstalledCommand::class]]);
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
        $this->app->singleton(Config::class, static fn(Container $app): Config => ConfigFactory::create(self::raw($app), (string) $app->make(Application::class)->environment(), $app->make(self::LOGGER), self::package($app)->installed()));
        $this->app->singleton(KeyProviderInterface::class, static fn(Container $app): KeyProviderInterface => StaticKeyProvider::fromConfig($app->make(Config::class)));
        // http.client: a container binding or class of a PSR-18 client; resolved on the first request only.
        $this->app->singleton(TransportInterface::class, static fn(Container $app): TransportInterface => TransportFactory::lazy($app->make(Config::class), static fn(string $id): mixed => $app->make($id)));
        $this->app->singleton(UrlNormalizerInterface::class, static fn(Container $app): UrlNormalizerInterface => UrlNormalizerFactory::fromConfig($app->make(Config::class)));
        $this->app->singleton(ThrottleInterface::class, static fn(Container $app): ThrottleInterface => TokenBucket::fromConfig($app->make(Config::class), $app->make(self::LOGGER)));
        $this->app->singleton(self::FAILURE_CACHE, static function (Container $app): ?Psr16 {
            $store = $app->make(Config::class)->debounceStore ?? self::DEFAULT_DEBOUNCE_STORE;
            if (\in_array($store, [DebounceStoreFactory::MEMORY, DebounceStoreFactory::NONE], true)) {
                return null;
            }
            $cache = $app->make(CacheFactory::class)->store($store === self::DEFAULT_DEBOUNCE_STORE ? null : $store);

            return $cache instanceof Psr16 ? $cache : null;
        });
        $this->app->singleton(ClientInterface::class, static fn(Container $app): ClientInterface => new Client($app->make(TransportInterface::class), $app->make(KeyProviderInterface::class), $app->make(Config::class), $app->make(self::LOGGER), $app->make(ThrottleInterface::class), $app->make(UrlNormalizerInterface::class), self::failureCache($app)));
        // debounce.store: "cache" (the default store), a store name, "memory" or "none".
        $this->app->singleton(DebounceStoreInterface::class, static fn(Container $app): DebounceStoreInterface => DebounceStoreFactory::fromConfig(
            $app->make(Config::class),
            static fn(string $store): mixed => $app->make(CacheFactory::class)->store($store === self::DEFAULT_DEBOUNCE_STORE ? null : $store),
            self::DEFAULT_DEBOUNCE_STORE,
        ));
        // Where the submitter records every Result: nothing by default; bind your own (or indexnowkit/history) after the provider.
        $this->app->singleton(SubmissionStoreInterface::class, NullSubmissionStore::class);
        $this->app->singleton(self::EVENTS, static fn(Container $app): Psr14 => new EventDispatcherBridge($app->make(EventDispatcher::class)));
        $this->app->singleton(SubmitterInterface::class, static fn(Container $app): SubmitterInterface => new Submitter($app->make(ClientInterface::class), $app->make(Config::class), $app->make(DebounceStoreInterface::class), $app->make(self::LOGGER), $app->make(UrlNormalizerInterface::class), self::events($app), $app->make(SubmissionStoreInterface::class)));
        $this->app->scoped(CollectorInterface::class, static fn(Container $app): CollectorInterface => Collector::fromConfig($app->make(Config::class), $app->make(self::LOGGER)));
        $this->app->singleton(KeyFileResponder::class, static fn(Container $app): KeyFileResponder => KeyFileResponder::fromConfig($app->make(Config::class), $app->make(KeyProviderInterface::class)));
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
        $this->app->alias(LaravelRouteUrlResolver::class, RouteBindingFieldsInterface::class);
        // #[IndexNow(resolver: ...)]: a container binding, or any class the container can build.
        $this->app->singleton(ResolverLocatorInterface::class, static fn(Container $app): ResolverLocatorInterface => new ArrayResolverLocator(
            [],
            locate: static function (string $id) use ($app): ?object {
                if (!$app->bound($id) && !class_exists($id)) {
                    return null;
                }
                try {
                    $resolver = $app->make($id);
                } catch (BindingResolutionException $e) {
                    throw new ConfigurationException(\sprintf('IndexNow URL resolver "%s" cannot be built by the container: %s', $id, $e->getMessage()), 0, $e);
                }

                return \is_object($resolver) ? $resolver : null;
            },
            hint: 'a container binding',
        ));
        $this->app->singleton(UrlResolverInterface::class, static fn(Container $app): UrlResolverInterface => AttributeUrlResolver::fromConfig($app->make(Config::class), $app->make(AttributeReaderInterface::class), $app->make(RouteUrlResolverInterface::class), $app->make(ResolverLocatorInterface::class), $app->make(self::LOGGER)));
        $this->app->singleton(GuardedUrlResolver::class, static fn(Container $app): GuardedUrlResolver => new GuardedUrlResolver($app->make(UrlResolverInterface::class), $app->make(AttributeReaderInterface::class), $app->make(self::LOGGER)));
    }

    private function registerDispatch(): void
    {
        $this->app->singleton(DispatcherInterface::class, static function (Container $app): DispatcherInterface {
            $config = $app->make(Config::class);

            return DispatcherFactory::fromConfig($config, $app->make(SubmitterInterface::class), $app->make(self::LOGGER), static function () use ($app, $config): DispatcherInterface {
                $queue = self::block($app, 'queue');
                $connection = $queue['connection'] ?? null;
                $name = $queue['queue'] ?? null;
                $delay = $queue['delay'] ?? 0;

                return new QueueDispatcher($app->make(BusDispatcher::class), $config, $app->make(self::LOGGER), \is_string($connection) ? $connection : null, \is_string($name) ? $name : null, is_numeric($delay) ? (int) $delay : 0);
            });
        });
    }

    private function registerDiagnostics(): void
    {
        if ($this->sitemapPackage()->installed()) {
            SitemapServices::register($this->app, self::LOGGER);
            $sitemapCheck = SitemapServices::SPOOL_CHECK;
        } else {
            $this->app->singleton(self::SITEMAP_MISSING_CHECK, static function (Container $app): CheckInterface {
                /** @var array{sitemap?: array<string, mixed>} $defaults */
                $defaults = require __DIR__ . '/../config/indexnow.php';

                return self::package($app)->check(self::block($app, 'sitemap'), $defaults['sitemap'] ?? []);
            });
            $this->app->singleton(SitemapNotInstalledCommand::class, static fn(Container $app): SitemapNotInstalledCommand => new SitemapNotInstalledCommand(self::package($app)->notInstalledMessage()));
            $sitemapCheck = self::SITEMAP_MISSING_CHECK;
        }
        $this->app->singleton(QueueCheck::class);
        $this->app->singleton(DebounceStoreCheck::class, static fn(Container $app): DebounceStoreCheck => new DebounceStoreCheck($app->make(Config::class), $app->make(CacheStoreProbe::class)(...), self::DEFAULT_DEBOUNCE_STORE));
        $this->app->singleton(EloquentCheck::class, static fn(Container $app): EloquentCheck => new EloquentCheck((bool) (self::block($app, 'eloquent')['enabled'] ?? true) && $app->make(Config::class)->enabled));
        $this->app->tag([QueueCheck::class, DebounceStoreCheck::class, $sitemapCheck, EloquentCheck::class], self::CHECK_TAG);
        $this->registerConsole();
        $this->app->singleton(CheckerInterface::class, static fn(Container $app): CheckerInterface => new Checker($app->make(Config::class), $app->make(KeyProviderInterface::class), $app->make(TransportInterface::class), $app->tagged(self::CHECK_TAG)));
        $this->app->singleton(KeyFileController::class, static fn(Container $app): KeyFileController => new KeyFileController($app->make(KeyFileResponder::class), $app->make(Config::class)->keyFileMaxAge, $app->make(Config::class)->hosts !== []));
    }

    /**
     * The shared command bodies of the core (`IndexNowKit\Console\*Runner`) with Laravel words and bindings; the
     * artisan commands only parse their input. Rebind `SubjectLoaderInterface`, `ResultFormatterInterface` or
     * `SubmitterFactoryInterface` to change how models are found, how results are printed, what `--force` submits
     * through.
     */
    private function registerConsole(): void
    {
        $this->app->singleton(Vocabulary::class, static fn(): Vocabulary => new Vocabulary(
            subject: 'model',
            subjects: 'models',
            cli: 'php artisan',
            submitSubjects: 'indexnow:submit-model',
            configLocation: 'config/indexnow.php and INDEXNOW_* env vars',
            keyFileServedBy: 'by the package route',
        ));
        $this->app->singleton(SubjectLoaderInterface::class, static fn(Container $app): SubjectLoaderInterface => $app->make(ModelLoader::class));
        $this->app->singleton(ResultFormatterInterface::class, ResultRenderer::class);
        $this->app->singleton(SubmitterFactoryInterface::class, static fn(Container $app): SubmitterFactoryInterface => new SubmitterFactory(
            $app->make(TransportInterface::class),
            $app->make(KeyProviderInterface::class),
            $app->make(Config::class),
            $app->make(DebounceStoreInterface::class),
            $app->make(ThrottleInterface::class),
            $app->make(UrlNormalizerInterface::class),
            $app->make(self::LOGGER),
            self::events($app),
            self::failureCache($app),
            $app->make(SubmissionStoreInterface::class),
        ));
    }

    /** The `IndexNow` section of `php artisan about`: what a support request needs first, the key masked. */
    private function registerAbout(): void
    {
        if (!class_exists(AboutCommand::class)) {
            return;
        }
        AboutCommand::add('IndexNow', fn(): array => $this->aboutSection());
    }

    /**
     * @return array<string, string>
     */
    private function aboutSection(): array
    {
        $raw = self::raw($this->app);
        try {
            $config = $this->app->make(Config::class);
        } catch (Throwable $e) {
            return ['Core' => Version::get(), 'Configuration' => 'INVALID: ' . $e->getMessage()];
        }
        $dispatch = $raw['dispatch'] ?? $config->dispatch;

        return [
            'Core' => Version::get(),
            'Enabled' => $config->enabled ? 'yes' : 'NO',
            'Dry run' => $config->dryRun ? 'yes' : 'no',
            'Environment' => $config->environment ?? '-',
            'Base URL' => $config->baseUrl ?? '-',
            'Key' => $config->key !== null ? KeyValidator::mask($config->key) : ($config->hosts !== [] ? \sprintf('%d host(s)', \count($config->hosts)) : 'NONE'),
            'Engines' => implode(', ', $config->engines),
            'Dispatch' => \is_string($dispatch) ? $dispatch : $config->dispatch,
            'Debounce' => $config->debouncePerUrl . 's via ' . ($config->debounceStore ?? self::DEFAULT_DEBOUNCE_STORE),
            'Check' => 'php artisan indexnow:check --strict',
        ];
    }

    private function registerKeyFileRoute(): void
    {
        // Raw values on purpose: boot() must not build the Config (tests and packages bind their own after boot).
        $raw = self::raw($this->app);
        $keyFile = \is_array($raw['key_file'] ?? null) ? $raw['key_file'] : [];
        try {
            $enabled = Config::serveKeyFileFrom($raw);
        } catch (ConfigurationException) {
            return; // the Config build logs the broken value; no route until it is fixed
        }
        // @phpstan-ignore staticMethod.dynamicCall (larastan models routesAreCached() as static through the facade @mixin)
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

    private static function events(Container $app): ?Psr14
    {
        $events = $app->make(self::EVENTS);

        return $events instanceof Psr14 ? $events : null;
    }

    private static function failureCache(Container $app): ?Psr16
    {
        $cache = $app->make(self::FAILURE_CACHE);

        return $cache instanceof Psr16 ? $cache : null;
    }

    private function sitemapPackage(): OptionalPackage
    {
        return self::package($this->app);
    }

    private static function package(Container $app): OptionalPackage
    {
        $package = $app->make(self::SITEMAP_PACKAGE);
        \assert($package instanceof OptionalPackage);

        return $package;
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
