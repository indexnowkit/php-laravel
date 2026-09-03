# Extending

The service provider registers one container binding per core interface. Replace any of them with
`$this->app->bind()` / `singleton()` / `extend()` in your own provider (register it after the package, or bind in
`register()` — the package resolves everything lazily).

## Bindings

| Abstract | Default | Replace it to |
|---|---|---|
| `IndexNowKit\Config` | `ConfigFactory::create(config('indexnow'))` | — (edit the config) |
| `IndexNowKit\Key\KeyProviderInterface` | `StaticKeyProvider::fromConfig()` | keys from a database (multi-tenant); honour `$host` in `isKnownKey()` |
| `IndexNowKit\Http\TransportInterface` | `LazyTransport` over a discovered PSR-18 client | your own HTTP stack (or set `http.client`) |
| `IndexNowKit\Url\UrlNormalizerInterface` | `UrlNormalizer` | canonical form: strip tracking parameters, trailing-slash policy |
| `IndexNowKit\Throttle\ThrottleInterface` | `TokenBucket` | a shared rate limiter |
| `IndexNowKit\ClientInterface` | `Client` | — |
| `IndexNowKit\Debounce\DebounceStoreInterface` | by `debounce.store` | another window store |
| `IndexNowKit\SubmitterInterface` | `Submitter` | wrap with `RetryingSubmitter`, add listeners for metrics |
| `IndexNowKit\Collector\CollectorInterface` (scoped) | `Collector` | a durable outbox, a per-tenant buffer |
| `IndexNowKit\Attribute\AttributeReaderInterface` / `RuleRegistry` | `RuleRegistry` over `AttributeReader` | your own rule source |
| `IndexNowKit\Url\RouteUrlResolverInterface` / `LaravelRouteUrlResolver` | the router bridge | another URL scheme |
| `IndexNowKit\Url\ResolverLocatorInterface` | `ContainerResolverLocator` | — |
| `IndexNowKit\Url\UrlResolverInterface` | `AttributeUrlResolver` | replace the whole "object → URLs" step |
| `IndexNowKit\Url\GuardedUrlResolver`, `ObjectChangeHandler` | the facade's | — |
| `IndexNowKit\Dispatch\DispatcherInterface` | by `dispatch` | another delivery (an outbox table, a bus) |
| `IndexNowKit\IndexNowKit` | the core facade | — |
| `IndexNowKit\Key\KeyFileResponder` | over the key provider | — |
| `IndexNowKit\Check\CheckerInterface` | `Checker` with the tagged checks | — |
| `IndexNowKit\Sitemap\SitemapSourceInterface` | `SitemapReader` | filter, rewrite or replace the sitemap source |
| `IndexNowKit\Laravel\IndexNowManager` | facade root | — |
| `IndexNowKit\Laravel\Eloquent\IndexNowObserver` (singleton) | the observer | — |
| `IndexNowKit\Laravel\Console\{ModelLoader, ResultRenderer, SubmitterFactory}` | | model lookup (tenant scoping), command output, `--force`/`--dry-run` submitters |
| `indexnowkit.logger` | `Log::channel(logging.channel)` | a PSR-3 logger of your own (tests: `ArrayLogger`) |

## Custom resolvers

```php
#[IndexNow(resolver: ProductUrlResolver::class)]
class Product extends Model { use IndexNowable; }

final class ProductUrlResolver implements UrlResolverInterface
{
    public function __construct(private readonly UrlGenerator $urls) {}   // constructor dependencies are injected

    public function resolve(object $subject, Event $event): array
    {
        return [$this->urls->route('products.show', ['product' => $subject], true)];
    }
}
```

The class is built by the container; a binding id works the same (`resolver: 'shop.product_urls'`). An unknown id
is a logged configuration error, not an exception.

## Rules registered at runtime

```php
IndexNowKit::observe(Vendor\Package\Post::class, [new IndexNow(route: 'posts.show', params: ['post' => 'self'])], new IndexNowDefaults(when: 'published'));
IndexNowKit::observe(Vendor\Package\Page::class);                       // the class carries its own attributes, only the observer is missing
IndexNowKit::rules()->registerFor(Node::class, fn (Node $node): ?RuleSet => $node->type === 'page' ? RuleCompiler::compile(...) : null);
```

## Extra checks

Anything implementing `IndexNowKit\Check\CheckInterface` and tagged `indexnowkit.check` is printed by
`indexnow:check`:

```php
$this->app->singleton(CdnPurgeCheck::class);
$this->app->tag([CdnPurgeCheck::class], IndexNowKitServiceProvider::CHECK_TAG);
```

Add lines to the report; never throw — a failing check is an error line.

## Submission results

`IndexNowKit::kit()->submitter->addListener(fn (Result $result) => ...)` receives every `Result` (engine, host,
status, reason, HTTP code, URL count) for metrics or an admin log. Register it on the bound `SubmitterInterface`
instance, which is what the observer, the job and the commands use (commands with `--force` / `--dry-run` build a
separate submitter through `SubmitterFactory`).

## Reading model attributes

`#[IndexNow]` accessors on Eloquent models go through `Eloquent\EloquentSubjectReader`, registered with the core's
`ParamExtractor::registerReader()`. It claims attributes, casts, accessors and relations (methods with a declared
`Relation` return type, or already loaded); anything else falls to the core DSL (methods, properties). An accessor
that matches nothing is a `ConfigurationException` logged at `error`, not a silent `null`.
