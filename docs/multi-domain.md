# Multiple domains and locales

## One application, several hosts

Every host gets its own key (engines verify `https://<host>/<key>.txt` on the submitted host):

```php
'key' => env('INDEXNOW_KEY'),                       // www.example.com, the base_url host
'base_url' => 'https://www.example.com',
'hosts' => [
    'example.de' => env('INDEXNOW_KEY_DE'),
    'shop.example.com' => [
        'key' => env('INDEXNOW_KEY_SHOP'),
        'base_url' => 'https://shop.example.com',    // origin for this host's URLs outside requests
        'engines' => ['yandex', 'bing'],             // per-host engine list
    ],
],
'strict_hosts' => true,                             // hosts not listed are skipped, not sent under the default key
```

The key file route serves each host's own key only (a request for `example.de`'s key on `www.example.com` is 404)
and answers with `Vary: Host`, so a shared CDN never caches one host's file for another. `indexnow:check` fetches
every host's key file; `--host=example.de` limits it to one.

## Rules on another host

```php
#[IndexNow(route: 'products.show', params: ['product' => 'self'], host: 'shop.example.com')]
```

`host` can also be an accessor (`host: 'tenant.domain'`) for multi-tenant models. The URL is generated through the
route and rebased onto `hosts.<host>.base_url`, else `https://<host>`. A route with its own `Route::domain()` keeps
that domain.

## Locales

```php
'router' => ['locales' => ['en', 'de'], 'locale_parameter' => 'locale', 'set_app_locale' => true],
'locale_hosts' => ['de' => 'example.de'],           // optional: one host per locale
```

```php
#[IndexNow(route: 'articles.show', params: ['article' => 'self'], locales: 'all')]
```

- `locales: 'current'` (default) generates one URL; `'all'` one per `router.locales`; a list as given.
- The locale is added as the `router.locale_parameter` route parameter **only when the route declares it**
  (`/{locale}/articles/{article}`), so no query string sneaks in. With `set_app_locale`, `App::setLocale()` is switched
  for the duration of the generation and restored, which is what packages with localized slugs read.
- With `locale_hosts`, a rule without `host` generates each locale on that locale's host and under that host's key.

## Origin of generated URLs

| Context | Origin |
|---|---|
| HTTP request | whatever Laravel generated (the request host) |
| artisan, queue worker | `base_url` (Laravel's console request is `APP_URL`; the package rebases scheme, host and port) |
| rule with `host:` | `hosts.<host>.base_url`, else `https://<host>` |
| route with `Route::domain()` | the route's domain, always |

A staging copy reached under another hostname would otherwise submit its URLs under the production key; that is what
`strict_hosts: true` prevents, and why `indexnow:check` warns when it is off in production.

## www and apex

`example.com` and `www.example.com` are two hosts to IndexNow: each needs its own key file, and a URL submitted
under the other one's key answers 422. Pick the canonical one (the one your pages link to and `<link
rel="canonical">` names), put it in `base_url`, redirect the other with `301`, and do not list it in `hosts` —
listing both would announce two copies of every page. With `strict_hosts: true` a request that reached the
application under the non-canonical name submits nothing instead of announcing duplicates.

## hreflang clusters

Localized pages that point at each other with `hreflang` are one cluster to the engines: when one changes, announce
the cluster. A rule with `locales: 'all'` does that for the locales of one model; for locales living on other hosts
`locale_hosts` sends each locale to its host under that host's key. When translations are separate objects, `via:`
walks to them:

```php
#[IndexNow(route: 'article_show', params: ['slug' => 'slug'], locales: 'all')]   // every locale of this article
#[IndexNow(via: 'translations')]                                                 // or: the sibling objects' own rules
```
