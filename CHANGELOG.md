# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed".

## [0.5.0] — 2026-09-04

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
