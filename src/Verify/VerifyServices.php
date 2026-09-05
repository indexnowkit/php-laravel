<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Verify;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Check\StaticCheck;
use IndexNowKit\Config;
use IndexNowKit\Http\TransportFactory;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Laravel\Check\ModelSampler;
use IndexNowKit\Laravel\Check\SampleOptions;
use IndexNowKit\Laravel\Check\VerifySampleCheck;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Verify\Check\SampleCheck;
use IndexNowKit\Verify\PageSignals;
use IndexNowKit\Verify\RobotsCache;
use IndexNowKit\Verify\VerifyConfig;
use IndexNowKit\Verify\VerifyingSubmitter;
use IndexNowKit\Verify\VerifyingSubmitterFactory;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * The verify bindings: the only wiring of the package that reads `IndexNowKit\Verify\*`, called by the provider
 * when {@see package()} says the package is installed ({@see IndexNowKitServiceProvider::VERIFY_PACKAGE}). With
 * `verify.enabled: true` the `SubmitterInterface` and `SubmitterFactoryInterface` bindings are extended in place,
 * so `dispatch: sync`, the queue job and every command submit through the pre-flight.
 */
final class VerifyServices
{
    /** Container id of the pre-flight transport (`verify.timeout`, `verify.user_agent`, the application's `http.client`). */
    public const TRANSPORT = 'indexnowkit.verify.transport';
    /** Container id of the `check` line about the package (`verify.installed`). */
    public const CHECK = 'indexnowkit.check.verify';
    /** Container id of the `check` warning with `dispatch: sync` (`verify.dispatch`), bound only then. */
    public const DISPATCH_CHECK = 'indexnowkit.check.verify_dispatch';

    /**
     * The one predicate for `indexnowkit/verify` (safe to call without the package: `::class` on an absent class
     * is a string); null = detect, false = wire as if the package were absent (tests).
     */
    public static function package(?bool $installed = null): OptionalPackage
    {
        return new OptionalPackage('indexnowkit/verify', PageSignals::class, 'verify', $installed);
    }

    /**
     * The dotted keys of the `verify` block, for `Config\ConfigFactory` (`VerifyConfig::OPTIONS`).
     *
     * @return list<string>
     */
    public static function options(): array
    {
        return VerifyConfig::OPTIONS;
    }

    /**
     * @param string $logger       container id of the PSR-3 logger
     * @param string $events       container id of the PSR-14 dispatcher (`?EventDispatcherInterface`)
     * @param string $failureCache container id of the PSR-16 cache behind `debounce.store` (`?CacheInterface`)
     * @param string $unverified   container id the plain command submitter factory stays bound under
     */
    public static function register(Container $app, string $logger, string $events, string $failureCache, string $unverified): void
    {
        $app->singleton(VerifyConfig::class, static fn(Container $app): VerifyConfig => VerifyConfig::loadOrDisabled(self::block($app), $app->make($logger), 'php artisan indexnow:check'));
        $app->singleton(self::TRANSPORT, static function (Container $app): TransportInterface {
            $verify = $app->make(VerifyConfig::class);

            return TransportFactory::lazy($verify->transportConfig($app->make(Config::class)), static fn(string $id): mixed => $app->make($id), ['User-Agent' => $verify->userAgent()]);
        });
        $app->singleton(RobotsCache::class, static function (Container $app) use ($logger, $failureCache): RobotsCache {
            $cache = $app->make($failureCache);

            return new RobotsCache($app->make(self::TRANSPORT), $cache instanceof CacheInterface ? $cache : null, $app->make(Config::class)->debounceKeyPrefix, $app->make(VerifyConfig::class)->robotsCacheTtl, $app->make($logger));
        });
        $app->singleton(self::CHECK, static function (Container $app): CheckInterface {
            $verify = $app->make(VerifyConfig::class);
            $line = $verify->enabled
                ? \sprintf('verify: enabled (redirect: %s, non_canonical: %s, origin_error: %s)', $verify->redirect->value, $verify->nonCanonical->value, $verify->originError->value)
                : 'verify: installed, disabled (verify.enabled: false)';

            return new StaticCheck(CheckLevel::Ok, $line, self::package(true)->checkCode());
        });
        $app->singleton(self::DISPATCH_CHECK, static function (Container $app): CheckInterface {
            $warn = $app->make(VerifyConfig::class)->enabled && $app->make(Config::class)->dispatch === 'sync';

            return new class ($warn) implements CheckInterface {
                public function __construct(private readonly bool $warn) {}

                public function check(CheckReport $report): void
                {
                    if ($this->warn) {
                        $report->warning('verify: verify.enabled with dispatch: sync fetches your own pages inside the web request; use dispatch: queue', 'verify.dispatch');
                    }
                }
            };
        });
        $app->singleton(VerifySampleCheck::class, static fn(Container $app): VerifySampleCheck => new VerifySampleCheck(
            $app->make(SampleOptions::class),
            static fn(array $urls, array $classes): CheckInterface => new SampleCheck($urls, $classes, $app->make(self::TRANSPORT), $app->make(VerifyConfig::class), $app->make(UrlNormalizerInterface::class), $app->make(KeyProviderInterface::class), $app->make(ModelSampler::class)(...), $app->make(RobotsCache::class)),
        ));

        $psr14 = static function (Container $app) use ($events): ?EventDispatcherInterface {
            $dispatcher = $app->make($events);

            return $dispatcher instanceof EventDispatcherInterface ? $dispatcher : null;
        };
        $app->extend(SubmitterInterface::class, static function (SubmitterInterface $inner, Container $app) use ($logger, $psr14): SubmitterInterface {
            if (!$app->make(VerifyConfig::class)->enabled) {
                return $inner;
            }
            $inWebRequest = $app->make(Config::class)->dispatch === 'sync' && !$app->make(Application::class)->runningInConsole();

            return new VerifyingSubmitter($inner, $app->make(self::TRANSPORT), $app->make(VerifyConfig::class), $app->make(KeyProviderInterface::class), $app->make(UrlNormalizerInterface::class), $app->make($logger), $psr14($app), $app->make(SubmissionStoreInterface::class), $app->make(RobotsCache::class), null, $inWebRequest);
        });
        $app->extend(SubmitterFactoryInterface::class, static function (SubmitterFactoryInterface $inner, Container $app) use ($logger, $psr14): SubmitterFactoryInterface {
            if (!$app->make(VerifyConfig::class)->enabled) {
                return $inner;
            }

            return new VerifyingSubmitterFactory($inner, $app->make(self::TRANSPORT), $app->make(VerifyConfig::class), $app->make(KeyProviderInterface::class), $app->make(UrlNormalizerInterface::class), $app->make($logger), $psr14($app), $app->make(SubmissionStoreInterface::class), $app->make(RobotsCache::class));
        });
        $app->singleton($unverified, static fn(Container $app): SubmitterFactoryInterface => $app->make(SubmitterFactoryInterface::class) instanceof VerifyingSubmitterFactory ? self::plainFactory($app) : $app->make(SubmitterFactoryInterface::class));
    }

    /** The effective block, for `indexnow:config` and `about`. */
    public static function effective(Container $app): VerifyConfig
    {
        return $app->make(VerifyConfig::class);
    }

    /**
     * @return array<string, mixed>
     */
    private static function block(Container $app): array
    {
        $block = $app->make(Repository::class)->get('indexnow.verify');

        return \is_array($block) ? $block : [];
    }

    /** A second plain factory (the extended binding wraps every resolution; the plain one is rebuilt from the same parts). */
    private static function plainFactory(Container $app): SubmitterFactoryInterface
    {
        $factory = $app->make(SubmitterFactoryInterface::class);
        \assert($factory instanceof VerifyingSubmitterFactory);

        return $factory->inner();
    }
}
