<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\History;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Config;
use IndexNowKit\History\Adapter\HistoryServices as Package;
use IndexNowKit\History\Console\HistoryCommand;
use IndexNowKit\History\Console\HistoryRunner;
use IndexNowKit\History\Console\StatusCommand;
use IndexNowKit\History\Console\StatusRunner;
use IndexNowKit\History\HistoryConfig;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Laravel\IndexNowKitServiceProvider;
use IndexNowKit\Retry\ForbiddenCounter;
use IndexNowKit\Submission\NullSubmissionStore;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\Url\UrlNormalizerInterface;
use PDO;
use Psr\SimpleCache\CacheInterface;

/**
 * The history bindings: the container ids and the Laravel side (the config repository, the cache and database
 * managers, the queue facts) over the package's own wiring (`History\Adapter\HistoryServices`: the stores, the check,
 * the runners). Called by the provider when {@see package()} says the package is installed
 * ({@see IndexNowKitServiceProvider::HISTORY_PACKAGE}). With
 * `history.store` set the `SubmissionStoreInterface` binding is extended: the null store of the provider becomes
 * the package's store (a cache store, or a PDO of a database connection or a DSN), so sync flushes, the queue job,
 * the commands and the verify decorator record into it; a store the application bound itself is left alone.
 */
final class HistoryServices
{
    /** Container id of the `check` lines about the store (`history.store`, `history.records`). */
    public const CHECK = 'indexnowkit.check.history';

    /**
     * The one predicate for `indexnowkit/history`: the core's `OptionalPackage::history()`, so it answers without the
     * package (the package's own `HistoryServices` cannot be loaded then); null = detect, false = wire as if the
     * package were absent (tests).
     */
    public static function package(?bool $installed = null): OptionalPackage
    {
        return OptionalPackage::history($installed);
    }

    /**
     * The dotted keys of the `history` block, for `Config\ConfigFactory` (`HistoryConfig::OPTIONS`).
     *
     * @return list<string>
     */
    public static function options(): array
    {
        return Package::options();
    }

    /**
     * @return list<class-string> the artisan commands: the package's `History\Console\HistoryCommand` and `StatusCommand`, bound by {@see register()}
     */
    public static function commands(): array
    {
        return [HistoryCommand::class, StatusCommand::class];
    }

    /**
     * @param string $logger       container id of the PSR-3 logger
     * @param string $failureCache container id of the PSR-16 cache behind `debounce.store` (`?CacheInterface`)
     */
    public static function register(Container $app, string $logger, string $failureCache): void
    {
        $app->singleton(HistoryConfig::class, static fn(Container $app): HistoryConfig => Package::config(self::block($app), $app->make($logger), 'php artisan indexnow:check'));
        $app->extend(SubmissionStoreInterface::class, static function (SubmissionStoreInterface $inner, Container $app): SubmissionStoreInterface {
            $history = $app->make(HistoryConfig::class);
            if (!$inner instanceof NullSubmissionStore || $history->store === null) {
                return $inner; // the application's own store, or nothing to record into
            }

            return self::store($app, $history);
        });
        $app->singleton(ForbiddenCounter::class, static function (Container $app) use ($logger, $failureCache): ForbiddenCounter {
            $cache = $app->make($failureCache);

            return Package::forbiddenCounter($app->make(Config::class), $cache instanceof CacheInterface ? $cache : null, $app->make($logger));
        });
        $app->singleton(self::CHECK, static fn(Container $app): CheckInterface => Package::check($app->make(HistoryConfig::class), $app->make(SubmissionStoreInterface::class)));
        $app->singleton(HistoryRunner::class, static fn(Container $app): HistoryRunner => Package::historyRunner($app->make(HistoryConfig::class), $app->make(SubmissionStoreInterface::class), $app->make(UrlNormalizerInterface::class)));
        $app->singleton(StatusRunner::class, static fn(Container $app): StatusRunner => Package::statusRunner(
            $app->make(Config::class),
            $app->make(KeyProviderInterface::class),
            $app->make(ForbiddenCounter::class),
            self::debounceStoreDescription($app),
            $app->make(SubmissionStoreInterface::class),
            self::adapterFacts($app),
        ));
        // the command classes of the package, over the runners: artisan resolves them lazily by their #[AsCommand] names
        $app->singleton(HistoryCommand::class, static fn(Container $app): HistoryCommand => new HistoryCommand($app->make(HistoryRunner::class)));
        $app->singleton(StatusCommand::class, static fn(Container $app): StatusCommand => new StatusCommand($app->make(StatusRunner::class)));
    }

    /** The effective block, for `indexnow:config` and `about`. */
    public static function effective(Container $app): HistoryConfig
    {
        return $app->make(HistoryConfig::class);
    }

    /** The `History` line of `php artisan about`: the store, its size, or why there is none. */
    public static function aboutLine(Container $app): string
    {
        return Package::describe(self::effective($app), $app->make(SubmissionStoreInterface::class));
    }

    /** A PDO of `history.pdo.dsn`, throwing on every error (the store expects exceptions, not false). */
    public static function pdoFromDsn(string $dsn): PDO
    {
        return Package::pdoFromDsn($dsn);
    }

    /**
     * The store of `history.store`: `psr16` over the cache store of `debounce.store` (the default cache store when
     * that is `memory`/`none`), `pdo` over `DB::connection(history.pdo.service)->getPdo()` or a PDO of `history.pdo.dsn`.
     */
    private static function store(Container $app, HistoryConfig $history): SubmissionStoreInterface
    {
        if ($history->store === HistoryConfig::STORE_PDO) {
            $pdo = $history->pdoDsn !== null ? Package::pdoFromDsn($history->pdoDsn) : $app->make(DatabaseManager::class)->connection($history->pdoService)->getPdo();

            return Package::pdoStore($pdo, $history);
        }
        $config = $app->make(Config::class);

        return Package::psr16Store(self::cache($app, $config), $history, $config);
    }

    /** The cache store of `debounce.store` when it names one, else the application's default store. */
    private static function cache(Container $app, Config $config): CacheRepository
    {
        $name = Package::debounceCacheId($config);

        return $app->make(CacheFactory::class)->store($name === IndexNowKitServiceProvider::DEFAULT_DEBOUNCE_STORE ? null : $name);
    }

    /** `memory`, `none`, or `<store> (<StoreClass>)` — `cache (RedisStore)`, `redis (RedisStore)`, `cache (ArrayStore)`; the package's one text. */
    private static function debounceStoreDescription(Container $app): string
    {
        return Package::describeStore($app->make(Config::class)->debounceStore, IndexNowKitServiceProvider::DEFAULT_DEBOUNCE_STORE, static function (string $store) use ($app): object {
            $repository = $app->make(CacheFactory::class)->store($store === IndexNowKitServiceProvider::DEFAULT_DEBOUNCE_STORE ? null : $store);

            return method_exists($repository, 'getStore') ? $repository->getStore() : $repository;
        });
    }

    /**
     * With `dispatch: queue`: the connection and the queue the job goes to (the application's defaults when the
     * block leaves them null).
     *
     * @return (Closure(): array<string, scalar|null>)|null
     */
    private static function adapterFacts(Container $app): ?Closure
    {
        if ($app->make(Config::class)->dispatch !== 'queue') {
            return null;
        }

        return static function () use ($app): array {
            $repository = $app->make(Repository::class);
            $queue = $repository->get('indexnow.queue');
            $queue = \is_array($queue) ? $queue : [];
            $connection = $queue['connection'] ?? null;
            $name = $queue['queue'] ?? null;
            $default = $repository->get('queue.default');

            return [
                'connection' => \is_string($connection) && $connection !== '' ? $connection : (\is_string($default) ? $default : null),
                'queue' => \is_string($name) && $name !== '' ? $name : 'default',
            ];
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function block(Container $app): array
    {
        $block = $app->make(Repository::class)->get('indexnow.history');

        return \is_array($block) ? $block : [];
    }
}
