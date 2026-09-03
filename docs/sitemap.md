# Sitemaps

`php artisan indexnow:sitemap` submits every URL of a sitemap, a sitemap index, a text sitemap or a gzipped one,
reading it as a stream and sending every `batch.max_urls` URLs while it is still being read. A million-URL index
never has to fit in memory.

```bash
php artisan indexnow:sitemap                                   # sitemap.url, else <base_url>/sitemap.xml
php artisan indexnow:sitemap https://www.example.com/news.xml
php artisan indexnow:sitemap storage/app/sitemap.xml          # local file, no web server involved
php artisan indexnow:sitemap --changed-since="1 day"          # only <lastmod> newer than that (entries without lastmod are skipped)
php artisan indexnow:sitemap --dry-run                        # list, send nothing
php artisan indexnow:sitemap --json                           # one row per engine/host/status with url_count and batches
```

Schedule it once a day as a safety net for changes that bypassed the hooks:

```php
// routes/console.php
Schedule::command('indexnow:sitemap --changed-since="1 day"')->daily();
```

## Options

| Option | |
|---|---|
| `--changed-since=` | `"1 day"`, `"2 hours"`, `"2026-09-01"` |
| `--allow-foreign-hosts` | follow index entries hosted on another origin (a CDN) for this run; `sitemap.allow_foreign_hosts` makes it permanent |
| `-f, --force` | ignore the debounce window |
| `--dry-run` | print the entries instead of submitting |
| `--json` | machine-readable summary; with `--dry-run` a JSON array of URLs |

When the sitemap breaks midway (network, 5xx after `fetch_retries`), the batch read so far is still submitted, the
command exits 1 and says how much went out; the re-run is idempotent thanks to the debounce window.

## Configuration

```php
'sitemap' => [
    'enabled' => true, 'url' => null, 'max_depth' => 3, 'max_sitemaps' => 1000, 'max_bytes' => 52428800,
    'allow_foreign_hosts' => false, 'spool' => 'auto', 'spool_dir' => null, 'fetch_retries' => 2,
],
```

`spool` decides where a document is kept while parsing: `auto` uses a temp file and falls back to memory when the
temp dir is not writable (read-only containers), `disk` fails instead, `memory` never touches the disk (bounded by
`max_bytes`). `indexnow:check` reports the location, or why the temp dir is unusable.

## Your own source

The command depends on `IndexNowKit\Sitemap\SitemapSourceInterface`. Bind another implementation to read from a
database, filter entries or rewrite URLs:

```php
$this->app->singleton(SitemapSourceInterface::class, fn ($app) => new FilteringSource($app->make(SitemapReader::class)));
```

`--allow-foreign-hosts` only reaches the shipped `SitemapReader`; a custom source decides on its own.

`spatie/laravel-sitemap` users: point `sitemap.url` at the generated file (`public/sitemap.xml`) or pass the path as
the argument; the package reads it locally.
