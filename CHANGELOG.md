# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed".

## [0.15.0] — 2026-09-08


The test suite of this package stays on PHPUnit `^11.5`: `laravel/framework` 12/13 registers its error handler in a way
PHPUnit 12.5 rejects (`ErrorHandler::enable(): Argument #1 must be TestCase`), so the family-wide `^11.5 || ^12.0 || ^13.0`
of audit 0.13 W2 does not apply here until the framework does.

### Changed

- **The artisan commands are the command classes of the packages; `Console\*Command` of this package is gone** (wave
  M, spec 19 §2.1 and §4.1 — spec 18 §9 had said artisan commands must extend `Illuminate\Console\Command`, which is
  not so: `Illuminate\Console\Application::resolve()` takes any symfony/console command). `indexnow:check`,
  `indexnow:config`, `indexnow:submit`, `indexnow:submit-model`, `indexnow:explain`, `indexnow:key:generate` and the
  three "not installed" stubs are `IndexNowKit\Console\Command\*` of `indexnowkit/console` 0.5 (`submit-model` is
  `SubmitSubjectsCommand`, named by the vocabulary, registered through a `Symfony\Component\Console\Command\LazyCommand`
  so `php artisan` never builds it before it runs), `indexnow:sitemap` is `IndexNowKit\Sitemap\Console\SitemapCommand`
  (sitemap 0.8), `indexnow:history` and `indexnow:status` are `IndexNowKit\History\Console\HistoryCommand` /
  `StatusCommand` (history 0.4) — the same names, arguments, options, exit codes and `--json` as before, the same
  classes the Symfony bundle and the Yii3 package register. The class argument stays `model`
  (`Artisan::call('indexnow:submit-model', ['model' => Post::class])`), through the `classArgument` of the two
  commands. Each command is a binding of its own: `$this->app->extend(CheckCommand::class, …)` replaces one, a
  command of your own with the same name registered later shadows one. What `check` and `config` read is
  `Console\ConfigSource` (a `Console\ConfigSourceInterface`, bound). *Migration*: the twelve classes were `final`,
  nobody extended them; code that resolved one from the container resolves the package's class instead (the names
  are the same after `IndexNowKit\Console\Command\`, `…\Sitemap\Console\`, `…\History\Console\`).
  `Check\ModelSampler` is `IndexNowKit\Console\SubjectSampler` (the same class four adapters carried); `Console\ModelLoader`
  extends `IndexNowKit\Console\AbstractSubjectLoader` (its constructor is unchanged; `findOne()` / `findMany()` are what is Eloquent's).
- **`Check\RouterCheck` is the core's `Check\LocalesCheck`** (same code `router.locales`, same texts, one class for the
  three adapters that print the line; the classes it reads are the `--sample-class` values, as before). *Migration*:
  the class was listed in docs/bc.md as a stable name; a check registered by hand becomes `new LocalesCheck($locales,
  $reader, fn () => [Post::class], 'router.locales', 'locale')`. Decision §6.4 of spec 19: no wrapper kept.
- **`Queue\QueueDispatcher` is the push onto the bus around the core's `Dispatch\BatchingDispatcher`** (the constructor
  is unchanged); `Queue\SubmitUrlsJob::newId()` delegates to `BatchingDispatcher::newJobId()`; the log lines are the
  core's (`{count} URL(s) queued as job {id}`, `cannot queue {count} URL(s) (job {id}), they are lost: {error}` — the
  texts this adapter already printed). `Url\LaravelRouteUrlResolver` decides the locale expansion, the pinned origin
  and the rebase through the core's `Url\RouteOrigin` (the same behaviour, the warning text unchanged).
  `Check\CacheStoreProbe` writes the core's `DebounceStoreCheck::PROBE_KEY` (`indexnowkit_check`, no colon: PSR-16
  reserves it) where it read `indexnowkit:check`. `indexnow:status` describes the debounce store as
  `cache (ArrayStore)` — `<store> (<StoreClass>)`, the text of `History\Adapter\HistoryServices::describeStore()` shared
  with Yii2 and Yii3 — where it said `cache (array)`. `Config\ConfigFactory` folds the packages' options through
  `OptionalPackage::ownedOptions()` / `ignoredBlocks()`. The `php artisan about` lines of an absent package come
  from the core's predicate.
- **`Eloquent\IndexNowObserver` resolves through the change handler, not through the facade.** Its constructor now
  takes a `Closure(): ObjectChangeHandler` and a `Closure(list<string>): void` sink instead of `IndexNowKit`, so the
  first `save()` of any model builds the rules, the resolver and the extractor and nothing else: the submitter, the
  client, the transport, the throttle and the debounce store are built in the sink, once a save actually produced a
  URL. A change handler the container cannot build is one `error` line per process, never an exception in `save()`.
  Migration: an observer built by hand becomes `IndexNowObserver::forKit($kit, $logger, $enabled, $router)`; the
  container binding is unchanged, so applications using the trait or `IndexNowKit::observe()` do nothing.
  `Url\ObjectChangeHandler` is now a binding of its own (built over `AttributeReaderInterface`, `GuardedUrlResolver`
  and `ParamExtractor`) and is handed to the `IndexNowKit` binding, so replacing it changes both.
- **`Psr\Clock\ClockInterface` is a container binding** (`IndexNowKit\Clock\SystemClock` by default, bound only when
  the application has not bound one) and reaches the throttle, the debounce store, the submitter and the command
  submitter factory. Binding `IndexNowKit\Testing\FrozenClock` makes the debounce window and the submission records
  of `indexnowkit/history` deterministic in tests (docs/extending.md, docs/testing.md).
- **`Queue\SubmitUrlsJob` carries the attempts its batch already spent** (`$spentAttempts`, appended to the
  constructor with a default; `attempt()` is the batch-wide attempt number, `tries` is what is left of
  `retry.max_attempts`). A partially accepted batch is re-queued as a new job whose `attempts()` starts at one again,
  so before this `retry.max_attempts` bounded each job instead of the batch, and a steadily shrinking batch could be
  retried far more often than configured. Queued jobs from an older version keep running (`spentAttempts` defaults to 0).
- `Check\SampleOptions` and `Check\VerifySampleCheck` are **removed**: they were copies of the core's
  `IndexNowKit\Check\SampleOptions` and `IndexNowKit\Check\SampleGateCheck` (core 0.13), which are now bound under
  their own class names. Same container mechanics, same `verify.installed` lines, same `--sample` behaviour.
- The `resolver:` container lookup no longer wraps the container's exception itself: `Url\ArrayResolverLocator` does
  it for every adapter, so *any* failure (not only a `BindingResolutionException`) is now the one family text
  `IndexNow URL resolver "…" cannot be built by the container: …`.

### Added

- **The `router.locales` line of `indexnow:check`** (the core's `Check\LocalesCheck`, see "Changed"): the locales
  `locales: 'all'` expands to, and a warning naming `router.locales` when a rule the command can see
  (`--sample-class=<FQCN>`) asks for every locale while the list is empty. At run time the same situation is one
  warning per process from `Url\LaravelRouteUrlResolver`, which used to collapse to a single URL without a word.
- A service tagged `indexnowkit.check` that is not a `Check\CheckInterface` is now a `ConfigurationException` naming
  the tag when the checker is built, instead of a fatal in the middle of the check run.

### Fixed

- **Fixed: the package was a fatal without `indexnowkit/sitemap`, `indexnowkit/verify` or `indexnowkit/history`**
  (`Class "IndexNowKit\Sitemap\Adapter\SitemapServices" not found` when the provider registered or the config was
  built): `Config\ConfigFactory::factory()` and the provider asked the packages' `*Services::package()` whether the
  package is installed, and those classes live in the packages. They now ask the core's `Adapter\OptionalPackage::sitemap()`
  / `verify()` / `history()` (core 0.13.0); `Sitemap\SitemapServices::package()`, `Verify\VerifyServices::package()` and
  `History\HistoryServices::package()` delegate there too, so `overrideApplicationBindings()` in tests is unchanged.
  Same texts, same container ids. A new CI job removes the three packages and boots the application with detection
  (`OptionalPackagesDetectionTest`).
- Requires `indexnowkit/core ^0.13`, `indexnowkit/console ^0.5` and, when installed, `indexnowkit/history ^0.4` (the
  version cascade of wave L, spec 18: the artisan commands keep parsing over the runners, nothing changes here).

## [0.14.0] — 2026-09-07

### Changed

- **The optional packages wire themselves**: `Verify\VerifyServices`, `History\HistoryServices` and `Sitemap\SitemapServices`
  keep their container ids and `register()` but build every piece through `Verify\Adapter\VerifyServices`,
  `History\Adapter\HistoryServices` and `Sitemap\Adapter\SitemapServices` of the packages (verify 0.3, history 0.3,
  sitemap 0.7 — `conflict` with older ones); the check texts, the store and decorator constructions are no longer
  copied here. What stays is Laravel's: the config repository, the cache and database managers, `runningInConsole()`,
  the queue facts, `php artisan about`.
- Requires `indexnowkit/verify ^0.3`, `indexnowkit/history ^0.3`, `indexnowkit/sitemap ^0.7` when installed.

## [0.13.1] — 2026-09-07

### Changed

- `IndexNowManager::submitModels()` delegates to `IndexNowKit::submitEntities()`; the `UrlResolverInterface` binding passes
  the `ParamExtractor` binding as the third argument of `AttributeUrlResolver::fromConfig()` (core 0.12.0).
- Requires `indexnowkit/core ^0.12`.

## [0.13.0] — 2026-09-07

### Changed

- **`Queue\SubmitUrlsJob` re-queues only the rejected URLs** of a partially accepted batch, as a new job on the same
  connection and queue with the engine's delay; `release()` (which replays the whole payload) is used only when every URL
  was rejected. Before, a `Retry-After` longer than `debounce.per_url` re-sent the accepted URLs too.
- **`Queue\QueueDispatcher` pushes one job per `batch.max_urls` URLs**: a bulk import was one job an SQS payload limit
  rejected, and every URL was lost with one log line.
- `check` gets `verify.transport`; `config/indexnow.php` gets `verify.time_budget`.
- `publishes()` is registered in the console only, as Laravel's package conventions have it; `psr/log ^1.1` is gone from
  the constraint (the core requires `^2 || ^3`).
- Requires `indexnowkit/core ^0.11`, `indexnowkit/console ^0.4`; tests against `verify ^0.2`, `history ^0.2`, `sitemap ^0.6`.

## [0.12.0] — 2026-09-06

### Added

- **`IndexNowKit\Attribute\ParamExtractor` binding**: `new ParamExtractor(new EloquentSubjectReader())`, shared by the resolver,
  the change handler, the facade and `indexnow:explain`. Extend it for objects neither Eloquent nor the DSL can see into:
  `$this->app->extend(ParamExtractor::class, fn(ParamExtractor $e) => $e->with(new CmsFieldReader()))`.

### Changed

- Requires `indexnowkit/core ^0.10`; the provider no longer calls the removed static `ParamExtractor::registerReader()` (the
  reader is in the binding instead — the same accessors resolve the same way).

## [0.11.0] — 2026-09-06

### Added

- **`indexnowkit/verify` wiring** (spec 17 §6.1). `config/indexnow.php` gets the `verify` block (`enabled` from
  `INDEXNOW_VERIFY`, `redirect`, `non_canonical`, `origin_error`, `delay`, `timeout`, `max_redirects`, `max_batch`,
  `robots_cache_ttl`, `user_agent`); with the package and `verify.enabled: true` the `SubmitterInterface` and
  `SubmitterFactoryInterface` bindings are extended with `VerifyingSubmitter` / `VerifyingSubmitterFactory`
  (`Verify\VerifyServices`), so `dispatch: sync`, the queue job and every command verify. New bindings:
  `VerifyConfig`, `RobotsCache`, `VerifyServices::TRANSPORT` (the pre-flight transport), the plain factory under
  `IndexNowKitServiceProvider::UNVERIFIED_SUBMITTER_FACTORY`, the predicate under `VERIFY_PACKAGE`
  (`VerifyServices::package(false)` in tests), `PACKAGE_BLOCKS` (the extra sections of `indexnow:config`).
  `check` lines: `verify.installed`, `verify.dispatch` (warning with `dispatch: sync`), `verify.sample`. Without the
  package the block is ignored as a whole (`check` says so) and `--sample` is an error naming the install line.
- **`indexnow:check --sample=<url>` / `--sample-class=<FQCN>[:<id>]`** (repeatable; `Check\SampleOptions`,
  `Check\VerifySampleCheck`, `Check\ModelSampler` over the model loader).
- **`indexnow:config --json`** prints the `verify` section; **`php artisan about`** has a `Verify` line.
- **`indexnowkit/history` wiring** (spec 17 §6.2). `config/indexnow.php` gets the `history` block (`store` from
  `INDEXNOW_HISTORY_STORE`, `limit`, `key_prefix`, `pdo.dsn` from `INDEXNOW_HISTORY_PDO_DSN`, `pdo.service`,
  `pdo.table`, `retention_days`); with the package and `history.store: psr16|pdo` the `SubmissionStoreInterface`
  binding is extended (`History\HistoryServices`): the null store becomes `Psr16SubmissionStore` over the cache store
  of `debounce.store` (the default store with `memory`/`none`) or `PdoSubmissionStore` over
  `DB::connection(history.pdo.service)->getPdo()` / a PDO of `history.pdo.dsn` — never both — so sync flushes, the
  queue job, the commands and the verify decorator record into it; a store the application binds itself is left
  alone. The table is not created (see the package's `docs/migrations.md`). New bindings: `HistoryConfig`,
  `Retry\ForbiddenCounter`, `HistoryRunner`, `StatusRunner`, the predicate under `IndexNowKitServiceProvider::HISTORY_PACKAGE`
  (`HistoryServices::package(false)` in tests). Commands **`indexnow:history`** (`--host`, `--status`, `--url`,
  `--since`, `--limit`, `--json`, `--purge[=days]`) and **`indexnow:status`** (`--json` per the package's
  `status.schema.json`; the queue connection and queue as the adapter facts); without the package
  `Console\HistoryNotInstalledCommand` / `StatusNotInstalledCommand` print the install line and exit 1. `check`
  lines: `history.installed` (without the package), `history.store`, `history.records`. `indexnow:config --json`
  prints the `history` section; `php artisan about` has a `History` line.
- `Config\ConfigFactory::factory()/create()/build()` take an appended `?bool $verifyInstalled = null` and
  `?bool $historyInstalled = null`.

### Changed

- Requires `indexnowkit/core ^0.9`, `indexnowkit/console ^0.3` and (dev/suggest) `indexnowkit/sitemap ^0.5`,
  `indexnowkit/verify ^0.1`, `indexnowkit/history ^0.1`.

## [0.10.0] — 2026-09-06

### Changed

- Requires `indexnowkit/core ^0.8`, `indexnowkit/console ^0.2` and (dev/suggest) `indexnowkit/sitemap ^0.4`. Core 0.8 strips tracking parameters by default (`normalizer.strip_tracking_params`) and makes `Equals` in `params` an error (see the core changelog).

### Added

- The `indexnow:check` lines of the adapter carry stable codes (core 0.8, `check --json`): `queue.dispatch`,
  `queue.connection`, `queue.driver`, `eloquent.enabled`, plus the core's `debounce.store` and `sitemap.installed`.
  Listed in the core's `docs/check-codes.md`.
- `indexnow:check --json` (the report as JSON, schema `docs/check.schema.json` of `indexnowkit/console`), `--strict`
  (warnings fail the command: put it in the deploy pipeline) and a repeatable `--host` (console 0.2).
- `indexnow:key:generate --force` keeps the replaced key as `INDEXNOW_PREVIOUS_KEY` and refuses a second rotation while
  it is set; `--no-previous` and `--yes` decide (console 0.2).
- The 403 escalation of `Client` counts in the cache store behind `debounce.store` (core 0.8; the store's
  `increment()` makes it atomic): one `critical` line per streak for every worker. `IndexNowKitServiceProvider::FAILURE_CACHE`
  is the container id of that `?Psr\SimpleCache\CacheInterface` (null with `memory`/`none`).
- Binding `Submission\SubmissionStoreInterface` (`NullSubmissionStore` by default): the store the submitter and the
  command submitters record every `Result` in (core 0.8); rebind it after the provider to keep a history.
- Configuration block `normalizer` (`strip_tracking_params`, `tracking_params`, `trailing_slash`, `sort_query`) in
  `config/indexnow.php`: the canonical form of every URL (core 0.8); `UrlNormalizerInterface` is bound through
  `Url\UrlNormalizerFactory`. Tracking parameters are stripped by default.
- `indexnow:explain --json` and the `when` values in the text output (console 0.2).
- `indexnow:config` (`--json`): the effective configuration with masked keys plus this adapter's own keys (console 0.2).
- **Results are Laravel events** (spec 17 §5.7): every `Result` goes through the event dispatcher
  (`Event::listen(Result::class, …)`, Telescope, `Event::fake()`), from the submitter and the command submitters alike,
  through the PSR-14 bridge `Event\EventDispatcherBridge` (container id `IndexNowKitServiceProvider::EVENTS`).
- `php artisan about` has an `IndexNow` section: core version, enabled/dry-run, environment, base URL, masked key,
  engines, dispatch, debounce, the check command.

## [0.9.0] — 2026-09-06

### Changed

- Requires core 0.7: `Console\SubmitterFactory` / `Console\SubmitterFactoryInterface` are now
  `IndexNowKit\Adapter\SubmitterFactory` / `IndexNowKit\Adapter\SubmitterFactoryInterface`, `Console\ResultSummary` is
  `IndexNowKit\Submission\ResultSummary`. Application code that names them (a decorator of the `SubmitterFactoryInterface` binding) changes the `use` line; nothing else.
- The test suite requires `indexnowkit/testing ^0.1` (`require-dev`): the conformance kits and the H01–H05 assertions
  moved there from the core (`Testing\Conformance\KeyFileAssertions`, `CheckOutputAssertions`, `ReadmeAssertions`).
- Requires `indexnowkit/console ^0.1`: the runners and definitions the artisan commands are built on moved there from the core
  with their FQCN unchanged (`IndexNowKit\Console\*`); Composer installs it with this package, nothing to do.
- `Sitemap\SitemapSupport` (the `@internal` predicate with its static override) is gone: the provider binds an
  `IndexNowKit\Adapter\OptionalPackage` under `IndexNowKitServiceProvider::SITEMAP_PACKAGE` (`SitemapServices::package()`).
  A test that booted without the package through `SitemapSupport::$installed = false` binds
  `IndexNowKitServiceProvider::SITEMAP_PACKAGE => fn() => SitemapServices::package(false)` before the provider
  registers (Testbench: `overrideApplicationBindings()`; `defineEnvironment()` runs after `register()`). `Config\ConfigFactory::factory()`, `create()` and `build()` take an
  appended `?bool $sitemapInstalled = null`. The `check` line for a configured but ignored `sitemap` block is a
  warning now (it was ok). The invalid-block critical line comes from `SitemapConfig::loadOrDisabled()` (same text).

### Added

- `resources/boost/guidelines/core.blade.php`: Laravel Boost picks it up on `boost:install` (conventions, a model
  snippet, verification).

### Documentation

- README: the quick-start model is `tests/Readme/Post.php` verbatim (complete `use` lines, the `category` relation the
  `via:` rule reads, casts/defaults that make it run); `ReadmeQuickstartTest` compares the README block with the file
  and runs the model through the test application against the FakeTransport.
- README: "Notes for AI assistants" (package, minimal complete snippet, verification, pitfalls across the adapters);
  `ReadmeAiNotesTest` keeps it consistent with the commands and configuration keys.
- README "Operations": the production checklist first, then monitoring rules, deleted pages, what not to submit,
  multi-domain and troubleshooting. docs/troubleshooting.md: "Staging submitted its URLs" and "Duplicates with
  `memory` and several workers". docs/multi-domain.md: www and apex, hreflang clusters through `locales: 'all'` /
  `locale_hosts` / `via:`.
- Russian translation: docs/troubleshooting.ru.md (linked from README.ru.md).
- `homepage` in composer.json points at the docs site (https://indexnowkit.github.io/php/).

## [0.8.0] — 2026-09-05

Wave 0a of docs/spec/17 with core 0.6.0. **`indexnow:check` fails outside `production_environments` when a key is
configured and `INDEXNOW_DRY_RUN` is not set** (a staging copy with the production key submits real URLs). A staging
or preview environment that submits on purpose sets `INDEXNOW_DRY_RUN=0` and gets a warning instead.

### Changed

- Requires `indexnowkit/core ^0.6`; `indexnowkit/sitemap ^0.2` when installed. Laravel 12 and 13 (the badge and
  CONTRIBUTING said 11 by mistake).
- `config/indexnow.php` reads `'dry_run' => env('INDEXNOW_DRY_RUN')` without a cast, so an unset variable stays unset.
  **A config file published before 0.8.0 keeps `(bool) env('INDEXNOW_DRY_RUN', false)`**: with it, the staging case
  above is a warning, not an error, until the line is changed or the file re-published
  (`php artisan vendor:publish --tag=indexnow-config --force`).

### Added

- `internetarchive` and `amazon` accepted in `engines` (core 0.6).

### Fixed

- `"App\Models\Post" is not an Eloquent model` names the base class the command expects.

### Documentation

- README: the runtime-rules snippet imports `IndexNow`, `IndexNowDefaults`, `RuleSet`; the facade
  `Laravel\Facades\IndexNowKit` versus the core `IndexNowKit\IndexNowKit`; "Why this over X", "Notification, not
  indexing", the issues link. [docs/bc.md](docs/bc.md): config keys, env vars, artisan commands, bindings, facade,
  trait, job, route. The troubleshooting table quotes the new accessor message.

## [0.7.0] — 2026-09-05

`indexnowkit/sitemap` is optional again (docs/spec/16, wave C): the package suggests it instead of requiring it.
Configuration keys, command names, bindings and the facade do not change.

### Changed

- **`indexnowkit/sitemap` is no longer installed automatically.** If you use `indexnow:sitemap`, run
  `composer require indexnowkit/sitemap`; otherwise, after `composer update`, the command reports that the package is
  missing and exits with code 1. Requires `indexnowkit/core ^0.5.1`.
- Without the package: `indexnow:sitemap` is `Console\SitemapNotInstalledCommand` (same name, every argument and
  option accepted and ignored, prints `indexnowkit/sitemap is not installed: composer require indexnowkit/sitemap`,
  exit 1); `indexnow:check` prints `sitemap: not installed (composer require indexnowkit/sitemap)`, or `sitemap: not
  installed, the sitemap block in the configuration is ignored (…)` when `config/indexnow.php` changed the block from
  the package defaults; `Config\ConfigFactory` ignores the `sitemap` block as a whole (no "unknown option" warning);
  `SitemapConfig`, `SitemapSourceInterface`, `SitemapSpoolCheck` and `SitemapRunner` are not bound. Nothing is logged
  at boot or on a request.
- The sitemap bindings moved to `Sitemap\SitemapServices`, registered only when `Sitemap\SitemapSupport::installed()`
  holds (the predicate; `@internal` `SitemapSupport::$installed` forces it in tests). Only relevant if you reach into
  the provider yourself.

## [0.6.0] — 2026-09-05

The core 0.5 "adapter kit" release, second wave: the observer, the queue job and the commands are built on the
core's `Hook\ObserverHelper`, `Retry\WorkerOutcome` and `Console\Definitions`. Configuration keys, command
names, arguments, options, bindings and the facade do not change.

### Changed

- Requires `indexnowkit/core ^0.5` and `indexnowkit/sitemap ^0.1.1`.
- `Eloquent\IndexNowObserver` on `Hook\ObserverHelper`: what is Eloquent's stays (change set, previous state,
  `afterCommit()`); the log line for a resolve failure before a deletion is now the helper's
  `indexnow: cannot resolve the URLs of {class}: {error}` (was "... before deletion: ...").
- `Queue\SubmitUrlsJob` on `Retry\WorkerOutcome`: same behaviour (release with the policy's delay, fail after the
  last attempt or on a final rejection), the log lines now carry the attempt:
  `indexnow: {count} URL(s) of job {id} will be retried in {n}s (attempt {n})`.
- The artisan signatures are rendered from `Console\Definitions` / `Sitemap\Console\Definitions`
  (`CommandDefinition::laravelSignature()`): the same names, shortcuts, defaults and descriptions as the bundle and
  Yii2. `SubmitModelCommand` and `ExplainCommand` take the `Console\Vocabulary` binding in their constructor
  (resolved by the container). Two descriptions changed wording (`indexnow:submit-model`, the `model` argument).
- Tests: H01–H05 assert through the core's `Testing\KeyFileAssertions` and `Testing\CheckOutputAssertions`.

## [0.5.0] — 2026-09-05

The core 0.4 "adapter kit" release: the package is built on the core's factories and `Adapter\ConfigFactory`, and
the sitemap reader is `indexnowkit/sitemap` (required by this package, installed transitively). Configuration keys,
commands, bindings and the facade do not change.

### Changed

- Requires `indexnowkit/core ^0.4` and `indexnowkit/sitemap ^0.1`. The sitemap classes moved:
  `IndexNowKit\Sitemap\*` keep their names, `Console\SitemapRunner`/`SitemapOptions` are
  `Sitemap\Console\*`, `Check\SitemapSpoolCheck` is `Sitemap\Check\SitemapSpoolCheck` and takes a `SitemapConfig`
  (bound in the container). `IndexNowKit::sitemap()` is gone: resolve `SitemapSourceInterface` from the container.
- `php artisan indexnow:sitemap` refuses to run with `sitemap.enabled: false` (`sitemap.enabled is false.`, exit 2)
  instead of ignoring the flag; an invalid `sitemap` block is logged at `critical` and disables the command.
- `Config\ConfigFactory` is a declaration of the core's `Adapter\ConfigFactory`; `coreOptions()` is gone, `create()`
  and `build()` keep their signatures. A typo inside `key_file`/`sitemap` (`key_file.enabld`) is warned about again.
- `Check\CacheStoreCheck` is the core's `Check\DebounceStoreCheck` with `Check\CacheStoreProbe`;
  `Url\ContainerResolverLocator` is the core's `ArrayResolverLocator(locate:)`; both classes are removed.
  `ModelLoader` takes an optional list of namespaces and delegates to `Console\ClassNameResolver`.
- `IndexNowManager::submitModels()`/`urlsForAll()` delegate to `IndexNowKit::submitAll()`/`urlsForAll()`.
- The key file response headers come from `Config::keyFileHeaders()`; `key_file.cache_max_age` is a core option now.
- Dev tooling: phpstan runs on the `lowest` flavour too; larastan/phpstan floors are the current releases.

## [0.4.0] — 2026-09-04

### Changed

- **Requires Laravel 12 or 13** (`illuminate/* ^12.0 || ^13.0`); Laravel 11 left its security-fix window in March 2026
  and every 11.x release now carries advisories that Composer 2.9+ refuses to install by default. Stay on 0.3.x for
  Laravel 11.
- `Eloquent\IndexNowObserver` asks the router for route binding fields through the new
  `Eloquent\RouteBindingFieldsInterface` (implemented by `Url\LaravelRouteUrlResolver`, aliased in the container)
  instead of depending on the resolver class. `src/Eloquent` now needs only `illuminate/database` and the core;
  bind the interface to teach the observer another routing scheme. No behaviour change.

## [0.3.0] — 2026-09-04

### Changed

- **Requires `indexnowkit/core ^0.3`.** The command bodies moved to the core (`IndexNowKit\Console\*Runner`); the
  artisan commands only parse their input, so every framework prints the same output. Visible in the terminal:
  tables in the Symfony style (no borders), errors and warnings as blocks, `indexnow:explain` with titled sections.
- **`Console\ResultRenderer`, `Console\ResultSummary`, `Console\SubmitterFactory` are gone.** To change the output
  bind `IndexNowKit\Console\ResultFormatterInterface`; to wrap what `--force` / `--dry-run` submit through bind
  `IndexNowKit\Console\SubmitterFactoryInterface`. `Console\ModelLoader` implements
  `IndexNowKit\Console\SubjectLoaderInterface` (`byIds()` and `all()` take the `Event` instead of a `$withTrashed`
  flag); the commands resolve the interface, so bind it to your own loader for tenant scoping.
- **`Check\SitemapSpoolCheck` is the core's** (`IndexNowKit\Check\SitemapSpoolCheck`, built from the `sitemap`
  config block). The "eloquent: observers active" line of `indexnow:check` is `Check\EloquentCheck`, a tagged
  check like the others.
- The `handle()` signatures of the command classes changed (they receive their runner). They are `final` and
  registered by the provider; nothing to change unless you called them yourself.

## [0.2.1] — 2026-09-04

### Added

- Laravel 13 (`illuminate/* ^13.0`, PHP 8.3+). A fresh `laravel/laravel` project is on 13 already, so 0.2.0 could not be
  installed there.

### Fixed

- `IndexNowable::bootIndexNowable()` registers the observer's model events directly instead of calling
  `Model::observe()`, which instantiates the model and is rejected by Laravel 13 while the model is booting
  (`LogicException: ... may not be called on model ... while it is being booted`). `IndexNowObserver::EVENTS` lists
  the handled events.

## [0.2.0] — 2026-09-04

First release, on `indexnowkit/core ^0.2.2`. Laravel 11 and 12, PHP 8.2–8.5.

### Added

- **Eloquent hooks.** `Eloquent\IndexNowable` registers `Eloquent\IndexNowObserver` on a model; `#[IndexNow]`,
  `#[IndexNowDefaults]` and `#[IndexNowUrl]` from the core declare its pages. The observer is synchronous on purpose
  and resolves URLs while the old state is live (`getOriginal()` in `updated`, the row in `deleting`), then hands them
  over through `Connection::afterCommit()`: nothing leaves before the outermost transaction commits, a rolled-back
  transaction or savepoint discards them (conformance A01–A21, A05b/A05c). `SoftDeletes`: soft delete = deletion,
  `restore()` = creation. A changed slug (or route key behind `params: ['post' => 'self']`) announces the old URL as
  deleted together with the new one.
- **Eloquent attribute reader.** `Eloquent\EloquentSubjectReader` teaches the core's accessor DSL to read attributes,
  casts, accessors and relations of a model (`when: 'published'`, `params: ['slug' => 'slug']`, `via: 'category'`);
  methods (`isPublished()`) keep working; a typo is a logged configuration error, not a silent `null`. A model in a
  route parameter is passed to `route()` as an object (route model binding, `{post}` and `{post:slug}`).
- **Router bridge.** `Url\LaravelRouteUrlResolver`: `route()` with route model binding; URLs generated in the console
  or a worker are rebased onto `base_url`, a rule with `host:` onto `hosts.<host>.base_url`; routes with their own
  domain keep it; locales through `router.locales` / `router.locale_parameter` / `router.set_app_locale`.
  `Url\ContainerResolverLocator` builds `#[IndexNow(resolver: ...)]` classes through the container.
- **Delivery.** `dispatch: queue` (default): `Queue\SubmitUrlsJob` with `tries`/backoff from the core `RetryPolicy`
  (`retry.*`), `release()` with `Retry-After` on 429, `fail()` on 400/403/422 and after `max_attempts`; `queue.{connection,
  queue, delay}`. `dispatch: sync` sends in `app()->terminating()`; `none` collects only. The collector is a scoped
  binding (Octane) flushed on `terminating` and after every handled queue job.
- **Debounce** through `Cache::store()` (`debounce.store`: `cache`, a store name, `memory`, `none`).
- **Key file route** `GET /{key}.txt` without the `web` middleware group, per-host key, `Vary: Host` with a `hosts` map;
  `key_file.{enabled, path, host, cache_max_age, route_name, middleware}`; `route:cache` compatible.
- **Artisan**: `indexnow:key:generate [--write-env] [--force]`, `indexnow:check [--live] [--host] [--probe-url]` with the
  Laravel wiring lines (queue connection, cache store, sitemap spool, observers), `indexnow:submit`,
  `indexnow:submit-model <model> [ids] [--explain]`, `indexnow:explain <model> <id>`, `indexnow:sitemap` (streaming,
  batches of `batch.max_urls`, `ResultSummary`); `--force`, `--dry-run`, `--json` everywhere.
- **Facade** `Facades\IndexNowKit` over `IndexNowManager`: `submit()`, `submitModel()`, `submitModels()` (the manual
  path after bulk updates), `urlsFor()`, `explain()`, `collect()`, `flush()`, `observe()` for models without trait or
  attribute, `rules()` (the `RuleRegistry`).
- **Configuration** `config/indexnow.php` (publish tag `indexnow-config`): every core key plus `queue`, `key_file`,
  `router`, `eloquent`, `sitemap`, `debounce.store`, `http.client`, `logging.channel`. An invalid runtime value disables
  IndexNow with one `critical` log line instead of throwing; unknown keys are warned about; `indexnow:check` prints
  the exact error.
- Extra `Check\CheckInterface` services through the `indexnowkit.check` tag; every core interface has a container
  binding an application can replace ([docs/extending.md](docs/extending.md)).
- Tests: the core conformance kits (`CoreConformanceTestCase`, `OrmConformanceTestCase`) on `orchestra/testbench`,
  H01–H06, queue, soft deletes, multi-domain and locale scenarios.

[0.4.0]: https://github.com/indexnowkit/php-laravel/compare/0.3.0...0.4.0
[0.3.0]: https://github.com/indexnowkit/php-laravel/compare/0.2.1...0.3.0
[0.2.1]: https://github.com/indexnowkit/php-laravel/compare/0.2.0...0.2.1
[0.2.0]: https://github.com/indexnowkit/php-laravel/releases/tag/0.2.0
