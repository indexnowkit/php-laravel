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
use IndexNowKit\Client;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\History\Check\HistoryCheck;
use IndexNowKit\History\Console\HistoryRunner;
use IndexNowKit\History\Console\StatusRunner;
use IndexNowKit\History\HistoryConfig;
use IndexNowKit\History\HistoryStoreInterface;
use IndexNowKit\History\Pdo\PdoSubmissionStore;
use IndexNowKit\History\Psr16SubmissionStore;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Laravel\Console\HistoryCommand;
use IndexNowKit\Laravel\Console\StatusCommand;
use IndexNowKit\Laravel\IndexNowKitServiceProvider;
use IndexNowKit\Retry\ForbiddenCounter;
use IndexNowKit\Submission\NullSubmissionStore;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\Url\UrlNormalizerInterface;
use PDO;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use Throwable;

/**
 * The history bindings: the only wiring of the package that reads `IndexNowKit\History\*`, called by the provider
 * when {@see package()} says the package is installed ({@see IndexNowKitServiceProvider::HISTORY_PACKAGE}). With
 * `history.store` set the `SubmissionStoreInterface` binding is extended: the null store of the provider becomes
 * the package's store (a cache store, or a PDO of a database connection or a DSN), so sync flushes, the queue job,
 * the commands and the verify decorator record into it; a store the application bound itself is left alone.
 */
final class HistoryServices
{
    /** Container id of the `check` lines about the store (`history.store`, `history.records`). */
    public const CHECK = 'indexnowkit.check.history';

    /**
     * The one predicate for `indexnowkit/history` (safe to call without the package: `::class` on an absent class
     * is a string); null = detect, false = wire as if the package were absent (tests).
     */
    public static function package(?bool $installed = null): OptionalPackage
    {
        return new OptionalPackage('indexnowkit/history', HistoryConfig::class, 'history', $installed);
    }

    /**
     * The dotted keys of the `history` block, for `Config\ConfigFactory` (`HistoryConfig::OPTIONS`).
     *
     * @return list<string>
     */
    public static function options(): array
    {
        return HistoryConfig::OPTIONS;
    }

    /**
     * @return list<class-string>
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
        $app->singleton(HistoryConfig::class, static fn(Container $app): HistoryConfig => HistoryConfig::loadOrDisabled(self::block($app), $app->make($logger), 'php artisan indexnow:check'));
        $app->extend(SubmissionStoreInterface::class, static function (SubmissionStoreInterface $inner, Container $app): SubmissionStoreInterface {
            $history = $app->make(HistoryConfig::class);
            if (!$inner instanceof NullSubmissionStore || $history->store === null) {
                return $inner; // the application's own store, or nothing to record into
            }

            return self::store($app, $history);
        });
        $app->singleton(ForbiddenCounter::class, static function (Container $app) use ($logger, $failureCache): ForbiddenCounter {
            $cache = $app->make($failureCache);
            $config = $app->make(Config::class);

            return new ForbiddenCounter($cache instanceof CacheInterface ? $cache : null, $config->debounceKeyPrefix, $config->forbiddenEscalation, Client::FAILURE_CACHE_TTL, $app->make($logger));
        });
        $app->singleton(self::CHECK, static fn(Container $app): CheckInterface => new HistoryCheck($app->make(HistoryConfig::class), $app->make(SubmissionStoreInterface::class)));
        $app->singleton(HistoryRunner::class, static fn(Container $app): HistoryRunner => new HistoryRunner($app->make(SubmissionStoreInterface::class), $app->make(HistoryConfig::class), $app->make(UrlNormalizerInterface::class)));
        $app->singleton(StatusRunner::class, static fn(Container $app): StatusRunner => new StatusRunner(
            $app->make(Config::class),
            $app->make(KeyProviderInterface::class),
            $app->make(ForbiddenCounter::class),
            self::debounceStoreDescription($app),
            $app->make(SubmissionStoreInterface::class),
            self::adapterFacts($app),
        ));
    }

    /** The effective block, for `indexnow:config` and `about`. */
    public static function effective(Container $app): HistoryConfig
    {
        return $app->make(HistoryConfig::class);
    }

    /** The `History` line of `php artisan about`: the store, its size, or why there is none. */
    public static function aboutLine(Container $app): string
    {
        $history = self::effective($app);
        if ($history->store === null) {
            return 'off (history.store)';
        }
        $store = $app->make(SubmissionStoreInterface::class);
        $where = $history->store === HistoryConfig::STORE_PDO ? \sprintf('pdo (%s)', $history->pdoTable) : \sprintf('psr16 (%d records kept)', $history->limit);
        if (!$store instanceof HistoryStoreInterface) {
            return $store instanceof NullSubmissionStore ? $where : \sprintf('custom (%s)', $store::class);
        }
        try {
            return \sprintf('%s, %s records', $where, HistoryCheck::number($store->count()));
        } catch (Throwable $e) {
            return \sprintf('%s, store failed: %s', $where, $e->getMessage());
        }
    }

    /** A PDO of `history.pdo.dsn`, throwing on every error (the store expects exceptions, not false). */
    public static function pdoFromDsn(string $dsn): PDO
    {
        return new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * The store of `history.store`: `psr16` over the cache store of `debounce.store` (the default cache store when
     * that is `memory`/`none`), `pdo` over `DB::connection(history.pdo.service)->getPdo()` or a PDO of `history.pdo.dsn`.
     */
    private static function store(Container $app, HistoryConfig $history): SubmissionStoreInterface
    {
        if ($history->store === HistoryConfig::STORE_PDO) {
            $pdo = $history->pdoDsn !== null ? self::pdoFromDsn($history->pdoDsn) : $app->make(DatabaseManager::class)->connection($history->pdoService)->getPdo();
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return new PdoSubmissionStore($pdo, $history->pdoTable);
        }
        $config = $app->make(Config::class);

        return new Psr16SubmissionStore(self::cache($app, $config), $history->keyPrefix ?? $config->debounceKeyPrefix, $history->limit);
    }

    /** The cache store of `debounce.store` when it names one, else the application's default store. */
    private static function cache(Container $app, Config $config): CacheRepository
    {
        $store = $config->debounceStore ?? IndexNowKitServiceProvider::DEFAULT_DEBOUNCE_STORE;
        $name = \in_array($store, [DebounceStoreFactory::MEMORY, DebounceStoreFactory::NONE, IndexNowKitServiceProvider::DEFAULT_DEBOUNCE_STORE], true) ? null : $store;

        return $app->make(CacheFactory::class)->store($name);
    }

    /** `memory`, `none`, or `<store> (<driver>)` — `cache (redis)`, `redis (redis)`, `cache (array)`. */
    private static function debounceStoreDescription(Container $app): string
    {
        $store = $app->make(Config::class)->debounceStore ?? IndexNowKitServiceProvider::DEFAULT_DEBOUNCE_STORE;
        if (\in_array($store, [DebounceStoreFactory::MEMORY, DebounceStoreFactory::NONE], true)) {
            return $store;
        }
        try {
            $repository = $app->make(CacheFactory::class)->store($store === IndexNowKitServiceProvider::DEFAULT_DEBOUNCE_STORE ? null : $store);
            $driver = method_exists($repository, 'getStore') ? strtolower((string) preg_replace('/Store$/', '', (new ReflectionClass($repository->getStore()))->getShortName())) : '?';
        } catch (Throwable) {
            $driver = 'unavailable';
        }

        return \sprintf('%s (%s)', $store, $driver);
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
