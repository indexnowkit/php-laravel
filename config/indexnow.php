<?php

declare(strict_types=1);

/*
 * IndexNow configuration (indexnowkit/laravel). Keys mirror the family-wide schema of indexnowkit/core
 * (docs/configuration.md there); the blocks marked "Laravel" are handled by this package.
 * Verify with: php artisan indexnow:check
 */
return [
    // Kill switch: false = nothing is sent, changes are still logged at debug level.
    'enabled' => (bool) env('INDEXNOW_ENABLED', true),

    // The IndexNow key ([A-Za-z0-9-]{8,128}). Generate one: php artisan indexnow:key:generate --write-env
    'key' => env('INDEXNOW_KEY'),

    // Key of the previous rotation: its key file is still served, nothing is submitted under it.
    'previous_key' => env('INDEXNOW_PREVIOUS_KEY'),

    // Full URL of the key file when it is not https://<host>/<key>.txt (must be on the base_url host).
    'key_location' => env('INDEXNOW_KEY_LOCATION'),

    // Absolute URLs are generated on this origin outside HTTP requests (artisan, queue workers).
    // Required with dispatch "queue".
    'base_url' => env('INDEXNOW_BASE_URL', env('APP_URL')),

    // Multi-domain: host => key, or host => ['key' => ..., 'key_location' => ..., 'base_url' => ..., 'engines' => [...], 'previous_key' => ...]
    'hosts' => [],

    // true: URLs of hosts outside base_url/hosts are skipped instead of being sent under the default key.
    'strict_hosts' => (bool) env('INDEXNOW_STRICT_HOSTS', false),

    // Environment names that count as production (outside them a missing key enables dry_run instead of failing).
    'production_environments' => ['prod', 'production'],

    'max_url_length' => 2048,

    // api | yandex | bing | naver | seznam | yep | internetarchive | amazon | an endpoint URL | an alias from engine_aliases.
    'engines' => explode(',', (string) env('INDEXNOW_ENGINES', 'api')),
    'engine_aliases' => [],

    // locale => host, for rules with `locales` and no `host`: each locale is generated on its own host.
    'locale_hosts' => [],

    // How collected URLs leave the application: queue (SubmitUrlsJob, retries), sync (in app()->terminating()), none.
    'dispatch' => env('INDEXNOW_DISPATCH', 'queue'),

    // Laravel: queue settings of SubmitUrlsJob (dispatch: queue). null = application defaults.
    'queue' => [
        'connection' => env('INDEXNOW_QUEUE_CONNECTION'),
        'queue' => env('INDEXNOW_QUEUE'),
        'delay' => 0,
    ],

    // Log the request instead of sending it. Leave INDEXNOW_DRY_RUN unset in production. Outside production
    // `indexnow:check` fails when a key is configured and this is unset (a staging copy would submit for real);
    // set INDEXNOW_DRY_RUN=1 there, or INDEXNOW_DRY_RUN=0 when that environment submits on purpose.
    'dry_run' => env('INDEXNOW_DRY_RUN'),

    'batch' => [
        'max_urls' => 10000,
    ],

    'debounce' => [
        // Seconds during which the same URL is not resubmitted (0 = off). Yandex accepts a URL once per 10 minutes.
        'per_url' => 600,
        // Laravel: "cache" (default cache store), a store name ("redis"), "memory" (per process) or "none".
        'store' => env('INDEXNOW_DEBOUNCE_STORE', 'cache'),
        'key_prefix' => 'indexnowkit_',
    ],

    'throttle' => [
        'max_requests_per_minute' => 60,
    ],

    'http' => [
        'timeout' => 10,
        'user_agent' => null,
        // Laravel: container id or class of a PSR-18 client (null = discover one; Laravel ships Guzzle).
        'client' => null,
    ],

    // Retries of SubmitUrlsJob (dispatch: queue): tries and exponential backoff; Retry-After wins.
    'retry' => [
        'max_attempts' => 3,
        'base_delay' => 60,
        'multiplier' => 2.0,
        'max_delay' => 3600,
        'server_error_delay' => 5,
    ],

    'resolver' => [
        'max_via_depth' => 3,
        'max_via_fanout' => 100,
    ],

    'collector' => [
        'max_urls' => 0,
        'detect_leaks' => true,
    ],

    'logging' => [
        // Laravel: log channel (null = default channel).
        'channel' => env('INDEXNOW_LOG_CHANNEL'),
        'max_urls' => 20,
        'forbidden_escalation' => 5,
        'max_body' => 300,
        'levels' => [],
    ],

    // Laravel: the GET /{key}.txt route.
    'key_file' => [
        'enabled' => true,
        'path' => '/{key}.txt',
        'host' => null,
        'cache_max_age' => 300,
        'route_name' => 'indexnow.key_file',
        // No "web" group by default: no session, no CSRF, no cookies on the key file.
        'middleware' => [],
    ],

    // Laravel: the router bridge behind #[IndexNow(route: ...)].
    'router' => [
        // Locales generated for rules with `locales: 'all'` (empty = the current locale only).
        'locales' => [],
        // Route parameter that carries the locale, added only when the route declares it.
        'locale_parameter' => 'locale',
        // Switch App::setLocale() while generating a locale's URL (localized slugs), restored afterwards.
        'set_app_locale' => true,
    ],

    // Laravel: Eloquent observers (models using IndexNowable or registered with IndexNowKit::observe()).
    'eloquent' => [
        'enabled' => true,
    ],

    // Sitemap reader behind php artisan indexnow:sitemap. Needs indexnowkit/sitemap (composer require
    // indexnowkit/sitemap); without the package this block is ignored and indexnow:check says so.
    'sitemap' => [
        'enabled' => true,
        // Default sitemap URL (null = <base_url>/sitemap.xml).
        'url' => null,
        'max_depth' => 3,
        'max_sitemaps' => 1000,
        'max_bytes' => 52428800,
        'allow_foreign_hosts' => false,
        // auto | disk | memory: where a document is kept while parsing.
        'spool' => 'auto',
        'spool_dir' => null,
        'fetch_retries' => 2,
    ],
];
