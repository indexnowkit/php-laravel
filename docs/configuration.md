# Configuration

`config/indexnow.php` (publish it with `php artisan vendor:publish --tag=indexnow-config`; without the file every
key below uses its default). Keys shared with every indexnowkit adapter are described in the
[core configuration reference](https://github.com/indexnowkit/php/blob/main/packages/core/docs/configuration.md);
this page lists all of them briefly and the Laravel-only blocks in full.

Values come from `env()`, so they are validated at runtime: an invalid value never throws from a save or from
`terminating`. It is logged once at `critical` (`indexnow: invalid configuration, IndexNow is disabled until it is
fixed: ...`), IndexNow runs disabled, and `php artisan indexnow:check` prints the exact error. Unknown keys (typos)
are logged at `warning`.

## Core keys

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `INDEXNOW_ENABLED`, `true` | kill switch; `false` = nothing is sent, changes are logged at debug |
| `key` | `INDEXNOW_KEY` | the IndexNow key, `[A-Za-z0-9-]{8,128}` |
| `previous_key` | `INDEXNOW_PREVIOUS_KEY` | the key before a rotation: its file is still served, nothing is submitted under it |
| `key_location` | `INDEXNOW_KEY_LOCATION` | full URL of the key file when it is not `https://<host>/<key>.txt` |
| `base_url` | `INDEXNOW_BASE_URL`, then `APP_URL` | origin for URLs generated outside HTTP requests (artisan, workers); required with `dispatch: queue` |
| `hosts` | `[]` | `host => key` or `host => ['key', 'key_location', 'base_url', 'engines', 'previous_key']` |
| `strict_hosts` | `INDEXNOW_STRICT_HOSTS`, `false` | skip URLs of hosts outside `base_url` / `hosts` instead of sending them under the default key |
| `environment` | `app()->environment()` | feeds the non-production dry-run safety net |
| `production_environments` | `['prod', 'production']` | environments where a missing key is an error, not dry-run |
| `max_url_length` | `2048` | longer URLs are `invalid_url` |
| `engines` | `INDEXNOW_ENGINES`, `api` | `api`, `yandex`, `bing`, `naver`, `seznam`, `yep`, `internetarchive`, `amazon`, an endpoint URL or an alias |
| `engine_aliases` | `[]` | `alias => endpoint URL` |
| `locale_hosts` | `[]` | `locale => host` for rules with `locales` and no `host` |
| `dispatch` | `INDEXNOW_DISPATCH`, `queue` | `queue`, `sync`, `none` (see below) |
| `dry_run` | `INDEXNOW_DRY_RUN`, `false` | log the request instead of sending it |
| `batch.max_urls` | `10000` | URLs per request (protocol maximum) |
| `debounce.per_url` | `600` | seconds a URL is not resubmitted; `0` = off |
| `debounce.key_prefix` | `indexnowkit_` | cache key prefix of the shared window |
| `throttle.max_requests_per_minute` | `60` | token bucket per process |
| `http.timeout` | `10` | seconds |
| `http.user_agent` | `null` | override the `indexnowkit-php/x.y.z` agent |
| `retry.*` | `3 / 60 / 2.0 / 3600 / 5` | `max_attempts`, `base_delay`, `multiplier`, `max_delay`, `server_error_delay` of `SubmitUrlsJob` |
| `resolver.max_via_depth` / `max_via_fanout` | `3` / `100` | limits of `via:` |
| `collector.max_urls` / `detect_leaks` | `0` / `true` | early flush threshold; warn at shutdown about unflushed URLs |
| `logging.max_urls` / `forbidden_escalation` / `max_body` / `levels` | `20` / `5` / `300` / `[]` | log line shaping; see the core operations guide |

## Laravel keys

### `dispatch`

| Value | Behaviour |
|---|---|
| `queue` (default) | each flushed batch becomes a `SubmitUrlsJob` on `queue.connection` / `queue.queue`, delayed by `queue.delay` seconds; retries follow `retry.*`. `QUEUE_CONNECTION=sync` runs it inline. |
| `sync` | the batch is sent from `app()->terminating()`, after the response; 429/5xx are not retried |
| `none` | URLs are collected but never sent (drain `IndexNowKit::kit()->collector` yourself) |

```php
'queue' => [
    'connection' => env('INDEXNOW_QUEUE_CONNECTION'),   // null = queue.default
    'queue' => env('INDEXNOW_QUEUE'),                   // null = the connection's default queue
    'delay' => 0,                                       // seconds before the first attempt
],
```

Details, Horizon and failure handling: [queue.md](queue.md).

### `debounce.store`

| Value | Store |
|---|---|
| `cache` (default) | `Cache::store()` — the default cache store, shared by web requests and workers |
| a store name (`redis`, `array`) | that store |
| `memory` | per process; fine for CLI scripts and tests, warned about by `indexnow:check` |
| `none` | no debounce |

The window is best effort and fails open: an unusable store logs a warning and the URLs are still sent.

### `http.client`

`null` discovers a PSR-18 client (Guzzle in a default Laravel application, otherwise php-http/discovery). A class
name or container binding of a `Psr\Http\Client\ClientInterface` uses that client (proxy, extra headers, retries of
your own). The transport is built on first use, so a request that submits nothing never touches it.

### `key_file`

```php
'key_file' => [
    'enabled' => true,             // false = the route is not registered (serve the file yourself or set key_location)
    'path' => '/{key}.txt',        // must contain {key}
    'host' => null,                // restrict the route to a host (Route::domain())
    'cache_max_age' => 300,        // Cache-Control max-age; short on purpose: a cached old file means 403 after a rotation
    'route_name' => 'indexnow.key_file',
    'middleware' => [],            // no "web" group by default: no session, no CSRF, no cookies
],
```

The controller serves only a key that belongs to the requested host (404 otherwise) and adds `Vary: Host` when a
`hosts` map is configured. `serve_key_file` (core name) is accepted as an alias of `key_file.enabled`. The route is
compatible with `route:cache`.

### `router`

```php
'router' => [
    'locales' => [],               // locales generated for rules with locales: 'all' (empty = current only)
    'locale_parameter' => 'locale',// added to the route parameters only when the route declares {locale}
    'set_app_locale' => true,      // App::setLocale() while generating a locale's URL (localized slugs), restored after
],
```

Inside an HTTP request URLs keep the host Laravel generated them on; in the console (artisan, queue workers) they are
rebased onto `base_url`; a rule with `host:` uses `hosts.<host>.base_url`, else `https://<host>`. Routes with their
own `Route::domain()` keep it. Details: [multi-domain.md](multi-domain.md).

### `eloquent`

`eloquent.enabled: false` keeps the observer registered but inert: models using `IndexNowable` submit nothing,
manual submission still works. `indexnow:check` says so.

### `logging.channel`

Log channel name (`config/logging.php`); `null` = the default channel. Every service of the package logs there,
with messages prefixed `indexnow:`.

### `sitemap`

Needs `indexnowkit/sitemap` (`composer require indexnowkit/sitemap`); without the package the block is ignored and
`indexnow:check` says so.

```php
'sitemap' => [
    'enabled' => true,             // false = no indexnow:sitemap command
    'url' => null,                 // default argument (null = <base_url>/sitemap.xml)
    'max_depth' => 3,              // nested sitemap indexes
    'max_sitemaps' => 1000,
    'max_bytes' => 52428800,       // per document
    'allow_foreign_hosts' => false,// follow index entries on another origin (CDN)
    'spool' => 'auto',             // auto | disk | memory: where a document is kept while parsing
    'spool_dir' => null,           // temp directory (null = sys_get_temp_dir())
    'fetch_retries' => 2,          // after a network failure or 5xx
],
```

Details: [sitemap.md](sitemap.md).

## Environment variables

`INDEXNOW_KEY`, `INDEXNOW_PREVIOUS_KEY`, `INDEXNOW_KEY_LOCATION`, `INDEXNOW_BASE_URL`, `INDEXNOW_ENABLED`,
`INDEXNOW_STRICT_HOSTS`, `INDEXNOW_ENGINES` (comma-separated), `INDEXNOW_DISPATCH`, `INDEXNOW_DRY_RUN`,
`INDEXNOW_QUEUE_CONNECTION`, `INDEXNOW_QUEUE`, `INDEXNOW_DEBOUNCE_STORE`, `INDEXNOW_LOG_CHANNEL` are read by the
shipped config file. Anything else goes through your published copy.

## Startup checks

`php artisan indexnow:check` validates the configuration (exact error on failure), fetches every key file over HTTP,
and prints the Laravel wiring: the queue connection `SubmitUrlsJob` goes to (or that `sync` retries nothing), whether
the debounce cache store is usable, where sitemap documents are spooled, and whether observers are active. `--live`
adds a real probe request per engine.
