# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed".

## [Unreleased]

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

[Unreleased]: https://github.com/indexnowkit/php-laravel/compare/0.2.0...HEAD
[0.2.0]: https://github.com/indexnowkit/php-laravel/releases/tag/0.2.0
