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
| `IndexNowKit\Url\ResolverLocatorInterface` | `ArrayResolverLocator` over the container | — |
| `IndexNowKit\Url\UrlResolverInterface` | `AttributeUrlResolver` | replace the whole "object → URLs" step |
| `IndexNowKit\Url\GuardedUrlResolver` | over the URL resolver | — |
| `IndexNowKit\Url\ObjectChangeHandler` | over the rules, the resolver and the extractor | what an insert / update / delete means for URLs; this is what the Eloquent hooks resolve through |
| `Psr\Clock\ClockInterface` | `IndexNowKit\Clock\SystemClock` | the time the throttle, the debounce window and the submission records read (`IndexNowKit\Testing\FrozenClock` in tests) |
| `IndexNowKit\Dispatch\DispatcherInterface` | by `dispatch` | another delivery (an outbox table, a bus) |
| `IndexNowKit\IndexNowKit` | the core facade | — |
| `IndexNowKit\Key\KeyFileResponder` | over the key provider | — |
| `IndexNowKit\Check\CheckerInterface` | `Checker` with the tagged checks | — |
| `IndexNowKit\Sitemap\SitemapSourceInterface` | `SitemapReader` | filter, rewrite or replace the sitemap source (bound only with `indexnowkit/sitemap` installed) |
| `IndexNowKit\Laravel\IndexNowManager` | facade root | — |
| `IndexNowKit\Laravel\Eloquent\IndexNowObserver` (singleton) | the observer | — |
| `IndexNowKit\Laravel\Eloquent\RouteBindingFieldsInterface` | `LaravelRouteUrlResolver` | which model field a `{post:slug}` parameter binds to (drives rename detection) when routes come from elsewhere |
| `IndexNowKit\Console\SubjectLoaderInterface` | `Console\ModelLoader` | model lookup (tenant scoping, another id format) |
| `IndexNowKit\Console\ResultFormatterInterface` | `ResultRenderer` | command output (your JSON envelope or table style) |
| `IndexNowKit\Adapter\SubmitterFactoryInterface` | `SubmitterFactory` | what `--force` / `--dry-run` submit through |
| `IndexNowKit\Console\Vocabulary`, `Console\*Runner` | Laravel words; the command bodies | reuse a runner from your own command (a tenant loop over `SubmitSubjectsRunner`) |
| `IndexNowKit\Console\ConfigSourceInterface` | `Console\ConfigSource` | what `indexnow:check` and `indexnow:config` read (the config repository, its strict build, the package blocks) |
| `IndexNowKit\Console\Command\*`, `Sitemap\Console\SitemapCommand`, `History\Console\{History,Status}Command` | the command classes of the packages, one binding each | replace one command ("Replacing a command" below) |
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

Add lines to the report; never throw — a failing check is an error line. A tagged service that is not a
`CheckInterface` is a `ConfigurationException` naming the tag when the checker is built, not a fatal in the middle of
`indexnow:check`.

## Submission results

Every `Result` (engine, host, status, reason, HTTP code, URL count) is dispatched as a Laravel event, the object
itself: `Event::listen(Result::class, fn (Result $result) => ...)` receives it, Telescope's events watcher lists it,
`Event::fake()` catches it in tests. The submitter and the command submitters (`--force`, `--dry-run`, the sitemap
command) publish through the same PSR-14 bridge (`IndexNowKitServiceProvider::EVENTS`). The lower-level
`IndexNowKit::kit()->submitter->addListener(fn (Result $result) => ...)` still works and fires first.

`php artisan about` has an `IndexNow` section: core version, enabled/dry-run, environment, base URL, the masked key,
engines, dispatch, debounce — the first things a support request needs. `php artisan indexnow:config --json` is the
full effective configuration.

## Reading model attributes

`#[IndexNow]` accessors on Eloquent models go through `Eloquent\EloquentSubjectReader`, the reader of the
`IndexNowKit\Attribute\ParamExtractor` the provider binds (`ParamExtractor::class`, shared by the resolver, the change handler
and `indexnow:explain`). It claims attributes, casts, accessors and relations (methods with a declared `Relation` return
type, or already loaded); anything else falls to the core DSL (methods, properties). An accessor that matches nothing is
a `ConfigurationException` logged at `error`, not a silent `null`. Objects neither can see into (a CMS record behind
`get_field()`) get a reader of their own:

```php
$this->app->extend(ParamExtractor::class, fn(ParamExtractor $extractor) => $extractor->with(new CmsFieldReader()));
```

## What is the core's

`IndexNowObserver` keeps only what is Eloquent's (the change set from `getChanges()`/`getOriginal()`, the previous
state from `getRawOriginal()`, `Connection::afterCommit()`); guarding, logging and the URLs of a row about to be
deleted are the core's `Hook\ObserverHelper` (`forChanges()`). The hooks read the `ObjectChangeHandler` binding and
nothing else, so a `save()` that announces no URL never builds the submitter, the client, the transport, the throttle
or the debounce store; the facade is made in the sink, once there are URLs to collect. `IndexNowObserver::forKit()`
builds one over an existing facade. `SubmitUrlsJob` is `Retry\WorkerOutcome` plus `release()`/`fail()`
with the delay the `RetryPolicy` computes, and it carries the attempts the batch already spent (`spentAttempts`) so
`retry.max_attempts` bounds the batch and not each re-queued job. The artisan commands are the command
classes of `indexnowkit/console`, `indexnowkit/sitemap` and `indexnowkit/history` themselves — artisan runs any
symfony/console command — so `php artisan indexnow:submit-model --help` is byte for byte what the bundle and Yii3
print. A custom command over a core runner extends `Symfony\Component\Console\Command\Command` and calls
`Definitions::check()->applyTo($this)` in `configure()`, or extends `Illuminate\Console\Command` and declares its
own `$signature`.

## Replacing a command

Every command is a container binding under its class name, so the container's own tools apply:

```php
// decorate: the same name, your behaviour around the package's
$this->app->extend(\IndexNowKit\Console\Command\CheckCommand::class, fn ($command, $app) => new MyCheckCommand($command));

// or register a command of your own with the same name after the package: the later registration wins in artisan
$this->commands([MyCheckCommand::class]);
```

`indexnow:submit-model` is registered through a `Symfony\Component\Console\Command\LazyCommand` (its name comes from
the vocabulary, not from `#[AsCommand]`): replacing the `SubmitSubjectsCommand` binding is enough, the lazy wrapper
resolves it on first run.
