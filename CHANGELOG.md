# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed".

## [Unreleased]

### Added

- The `indexnow:check` lines of the adapter carry stable codes (core 0.8, `check --json`): `queue.dispatch`,
  `queue.connection`, `queue.driver`, `eloquent.enabled`, plus the core's `debounce.store` and `sitemap.installed`.
  Listed in the core's `docs/check-codes.md`.
- `indexnow:check --json` (the report as JSON, schema `docs/check.schema.json` of `indexnowkit/console`), `--strict`
  (warnings fail the command: put it in the deploy pipeline) and a repeatable `--host` (console 0.2).

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
