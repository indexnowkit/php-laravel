# Laravel IndexNow package — `indexnowkit/laravel`

Tell search engines about new, changed and deleted pages the moment an Eloquent model is committed.
One attribute on the model, one env variable, done.

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/laravel)](https://packagist.org/packages/indexnowkit/laravel)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/laravel)](https://packagist.org/packages/indexnowkit/laravel)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
[![Conformance](https://img.shields.io/badge/conformance-core%2022%2F22%20%C2%B7%20orm%2021%2F21%20%C2%B7%20http%206%2F6-brightgreen)](https://github.com/indexnowkit/spec)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4) ![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20)

[Русская версия](README.ru.md) · Issues and pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (the `php-*` repositories are read-only splits)

## Who gets notified

**Yandex, Bing (and DuckDuckGo via Bing), Naver, Seznam, Yep, Internet Archive, Amazon** — every engine in the
[IndexNow](https://www.indexnow.org) [registry](https://www.indexnow.org/searchengines.json). One request to the shared
endpoint reaches all of them; name engines explicitly only to reach a single one.

**Google: no.** Google does not support IndexNow, its sitemap ping endpoint is gone (404) and the
Indexing API is restricted to `JobPosting` / `BroadcastEvent`. This package will not pretend otherwise.

**Notification, not indexing.** IndexNow tells an engine that a URL changed; whether and when the page is crawled and
indexed is the engine's decision. See the result in Bing Webmaster Tools (IndexNow Insights) and Yandex.Webmaster
(Indexing → Reindex pages); a useful metric is the share of submitted URLs in the index after a few days. Deleted
pages: answer 410 (gone for good) or 404 (temporarily); for a move answer 301 and submit both URLs; a soft-404 or a
redirect to the home page does harm. Bing's URL Submission API and Google's Indexing API are different protocols and
not covered here.

## Why this over X

Most IndexNow packages are a thin HTTP client: you collect the URLs, you call it, you read the answer. This family
does the part that goes wrong in practice:

- **Declared on the model** (`#[IndexNow]`) and submitted from the ORM hooks — no controller code to forget.
- **After the commit**, not on flush: a rolled-back transaction announces nothing.
- **Debounce** (10 minutes per URL, shared through your cache), **batches** of up to 10 000 URLs, one key per host from env.
- **Answers handled**: 202 (key pending), 422, 429 with `Retry-After` back-off and a retry through your queue, 403 escalation.
- **`check` before the first submission** says what is wrong (key file, engines, queue, cache, environment); `explain` says why a URL was or was not sent.
- **One core** under the Symfony, Laravel, Yii2 and Doctrine adapters with a shared conformance suite: the same behaviour everywhere, documented once.


## Install

```bash
composer require indexnowkit/laravel
composer require indexnowkit/sitemap               # optional: the indexnow:sitemap command
php artisan vendor:publish --tag=indexnow-config   # config/indexnow.php (optional, every key has a default)
php artisan indexnow:key:generate --write-env      # adds INDEXNOW_KEY to .env
php artisan indexnow:check                         # config, key file reachable, queue, cache
```

The service provider is auto-discovered. Laravel ships Guzzle, which is the PSR-18 client the package discovers;
any other PSR-18 client works too (`indexnow.http.client`).

```dotenv
INDEXNOW_KEY=...                      # from key:generate
INDEXNOW_BASE_URL=https://www.example.com   # defaults to APP_URL; used by artisan and queue workers
```

## Declare what has a public page

`#[IndexNow]` is repeatable: one attribute per family of public URLs the model has. `IndexNowable` registers the
observer.

```php
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};
use IndexNowKit\Laravel\Eloquent\IndexNowable;

#[IndexNowDefaults(when: 'isPublished', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'posts.show', params: ['post' => 'self'])]                 // route model binding
#[IndexNow(route: 'posts.amp', params: ['post' => 'self'], when: 'hasAmp')]
#[IndexNow(via: 'category')]      // a changed post also refreshes its category page
#[IndexNow(urls: ['/'])]          // and the homepage
class Post extends Model
{
    use IndexNowable;

    public function isPublished(): bool { return $this->published; }
    public function hasAmp(): bool { return $this->amp; }
}
```

| Option | Meaning |
|---|---|
| `route` / `params` | route name and `param => attribute, method, "self", dotted.path` or a typed `Param\*` value |
| `resolver` | a `UrlResolverInterface` class or container binding for anything custom |
| `via` | a relation (or dotted path) whose pages are resubmitted |
| `url` / `urls` | a method returning the URL(s), or literal URLs |
| `when` / `whenFields` | bool attribute or method; drafts are skipped and `published → draft` is sent as a deletion |
| `fields` | for updates, submit only when one of these attributes changed |
| `events` | subset of `created`, `updated`, `deleted` |
| `locales` | `current` (default), `all` (`indexnow.router.locales`), or a list |
| `host` | generate this rule's URLs on another host (multi-domain) |
| `name` | stable rule id for logs, `indexnow:explain` and overriding in a subclass |

Accessors read Eloquent attributes, casts, accessors and relations (`category.slug`) and fall back to methods
(`isPublished()`). `params: ['post' => 'self']` passes the model to `route()`, so `{post}` and `{post:slug}` both
work. A `when` attribute that only has a **database** default is not on the model right after `create()`: give it a
model default (`protected $attributes = ['published' => false]`).

Full model, typed parameters, inheritance and the semantics table:
[core attribute reference](https://github.com/indexnowkit/php/blob/main/packages/core/docs/attribute-reference.md).

### Models you cannot annotate

```php
// AppServiceProvider::boot()
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults, RuleSet};
use IndexNowKit\Laravel\Facades\IndexNowKit;

IndexNowKit::observe(Product::class, [new IndexNow(route: 'products.show', params: ['product' => 'self'])], new IndexNowDefaults(when: 'is_active'));
IndexNowKit::rules()->registerFor(Page::class, fn (Page $page): ?RuleSet => ...);   // decided per object
```

Two classes are called `IndexNowKit`. The **facade** `IndexNowKit\Laravel\Facades\IndexNowKit` (above) proxies the
`IndexNowManager` of this package: `observe()`, `rules()`, `submitModel()`, `submitModels()`, `submit()`, `collect()`,
`flush()`, `explain()`. The **core** `IndexNowKit\IndexNowKit` is the same service without the Eloquent-specific parts;
inject it by type (`public function __construct(private IndexNowKit $indexNow)`) or take it from the facade with
`IndexNowKit::kit()`. Import one of them per file, or alias the other.

## Verify

```bash
php artisan indexnow:check          # config, key file reachable, engines, queue connection, cache store, spool
php artisan indexnow:check --live   # also sends a real probe request to every engine
```

Run it after every key rotation and after every deployment that touches the configuration.

## How it works

- Observer callbacks resolve URLs **while the old state is still live** (`getOriginal()` in `updated`, the row in
  `deleting`) and hand them over through `Connection::afterCommit()`: nothing leaves before the outermost
  transaction commits, a rolled-back transaction (or savepoint) discards them. `DB::transaction()` nesting is
  handled by Laravel's transaction manager.
- Every rule is classified separately: the article page can be an update while the AMP page of the same model is a
  deletion, in the same request.
- Everything collected during one request, artisan command or queue job is sent as **one batch** in
  `app()->terminating()` (or after each handled job), never inside your request.
- `dispatch: queue` (the default) pushes a `SubmitUrlsJob`; 429 and 5xx are retried with backoff, `Retry-After`
  wins, 403/422 fail the job so a broken key file shows up in `failed_jobs`. `QUEUE_CONNECTION=sync` runs it inline.
- `SoftDeletes`: soft delete is a deletion, `restore()` a creation, `forceDelete()` a deletion.
- A renamed page (changed slug, or a changed route key behind `self`) announces its old URL as deleted and the new
  one as updated, in the same batch.
- Nothing thrown from a rule, a resolver or the HTTP layer reaches your application: it is logged, the save
  succeeds.

## Commands

| Command | Options |
|---|---|
| `indexnow:check` | `--live` real probe · `--host=` one host · `--probe-url=` page for the probe |
| `indexnow:submit <urls...>` | `-f, --force` ignore debounce · `--dry-run` · `--json` |
| `indexnow:submit-model <model> [ids...]` | `--event=` · `--limit=` · `--explain` · `-f, --force` · `--dry-run` · `--json` |
| `indexnow:explain <model> <id>` | `--event=` — rules, `when`, URLs, key, debounce; sends nothing |
| `indexnow:sitemap [sitemap]` | `--changed-since="1 day"` · `--allow-foreign-hosts` · `-f, --force` · `--dry-run` · `--json` |
| `indexnow:key:generate` | `-l, --length` · `--alphanumeric` · `--write-env[=FILE]` (default `.env`) · `--force` rotate |

`<model>` accepts an FQCN or a short `App\Models` name.

### Sitemaps

`composer require indexnowkit/sitemap   # optional: the indexnow:sitemap command`

`indexnow:sitemap` with no argument reads `indexnow.sitemap.url`, else `<base_url>/sitemap.xml`; a local path works
too. Schedule it: `Schedule::command('indexnow:sitemap --changed-since="1 day"')->daily()`. Without the package
everything else works unchanged: `indexnow:sitemap` says `indexnowkit/sitemap is not installed: composer require
indexnowkit/sitemap` and exits 1, `indexnow:check` prints `sitemap: not installed (…)`, the `sitemap` block of
`config/indexnow.php` is ignored. Nothing is logged about it. Details: [docs/sitemap.md](docs/sitemap.md).

## Configuration

Every key of `config/indexnow.php`, its default and what it does: [docs/configuration.md](docs/configuration.md).

| Topic | |
|---|---|
| Queue, retries, Horizon | [docs/queue.md](docs/queue.md) |
| Multiple domains and locales | [docs/multi-domain.md](docs/multi-domain.md) |
| Sitemaps | [docs/sitemap.md](docs/sitemap.md) |
| Extending: bindings you can replace, custom resolvers, checks | [docs/extending.md](docs/extending.md) |
| Testing your integration | [docs/testing.md](docs/testing.md) |
| Troubleshooting | [docs/troubleshooting.md](docs/troubleshooting.md) |

## Debugging

1. **`php artisan indexnow:explain "App\Models\Post" 42`** walks the decision path for one model — rules, event
   subscription, `when`, `fields`, resolved URLs, normalization, host and key, debounce — and sends nothing.
2. **The log channel** (`indexnow.logging.channel`, default channel otherwise) carries everything; at `debug` it
   also says why a rule decided *not* to produce a URL. Messages and levels:
   [operations guide](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md).
3. **`failed_jobs`** holds batches an engine rejected permanently (403: key file not reachable).

An invalid configuration does not throw from a save: IndexNow is disabled, one `critical` line is logged, and
`indexnow:check` prints the exact error.

## Limitations

- `Model::query()->update()`, `delete()`, `insert()`, `upsert()` and `DB::table()` fire no model events (conformance
  A13): call `IndexNowKit::submitModels($query->get())` or `php artisan indexnow:submit-model` afterwards.
- `attach()` / `detach()` / `sync()` on a pivot fire no events on the owner. Put `$touches = ['posts']` on the related
  model: the owner's `updated` (only `updated_at` changed) reaches a rule without a `fields` filter.
- `dispatch: sync` depends on `terminating` firing. Under Octane it does; an early `exit()` or a fatal error discards
  the batch with a warning. Prefer the default `queue`.
- Sub-domains are separate hosts: give each its own key with the `hosts` map, and set `strict_hosts: true`.
- Outside production (`production_environments`, default `prod`/`production`), a missing `INDEXNOW_KEY` switches
  `dry_run` on instead of failing.

## Compatibility

Public API: `config/indexnow.php` keys, command names and options, the container bindings listed in
[docs/extending.md](docs/extending.md), `Facades\IndexNowKit` / `IndexNowManager`, `Eloquent\IndexNowable`,
`Queue\SubmitUrlsJob`. The core's rules apply, including the "may grow" interfaces:
[bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md); what this package itself keeps stable: [docs/bc.md](docs/bc.md). Before 1.0 a minor version may break; every
break is listed under "Changed" in [CHANGELOG.md](CHANGELOG.md) with the migration. Laravel 12 and 13, PHP 8.2–8.5 (Laravel 13 needs PHP 8.3).

## Other frameworks

| | |
|---|---|
| PHP | [core](https://github.com/indexnowkit/php/tree/main/packages/core), [symfony-bundle](https://github.com/indexnowkit/php/tree/main/packages/symfony-bundle), [doctrine](https://github.com/indexnowkit/php/tree/main/packages/doctrine), [laravel](https://github.com/indexnowkit/php/tree/main/packages/laravel), [yii2](https://github.com/indexnowkit/php/tree/main/packages/yii2) |
| JS/TS | @indexnowkit/core, next, prisma (soon) |
| Python | indexnowkit, indexnowkit-django (soon) |

Design rationale: [docs/spec](https://github.com/indexnowkit/php/tree/main/docs/spec). Changelog: [CHANGELOG.md](CHANGELOG.md).

MIT. IndexNow is a trademark of its owner; this project is independent and not affiliated with Microsoft, Yandex or indexnow.org.
