# Backward compatibility

`indexnowkit/laravel` follows SemVer. **Before 1.0, minor versions may contain breaking changes**; every one is listed
under "Changed" in [CHANGELOG.md](../CHANGELOG.md) with the migration. After 1.0 the rules below become the promise.
The core's tiers ("call", "implement", "may grow") apply to every core class you touch through this package:
[core bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md).

## What the package keeps stable

| Surface | Promise |
|---|---|
| **`config/indexnow.php` keys** ([configuration.md](configuration.md)) | Keys and their meaning stay; new keys are only added with a default. A published config file from an older minor keeps working; a rename ships the old key as deprecated for one minor and is listed in the changelog. |
| **Environment variables** the config file reads (`INDEXNOW_KEY`, `INDEXNOW_BASE_URL`, `INDEXNOW_DRY_RUN`, …) | Names stay; new ones are only added. |
| **Artisan commands and options** (`indexnow:check`, `indexnow:key:generate`, `indexnow:submit`, `indexnow:submit-model`, `indexnow:explain`, `indexnow:sitemap`) | Names, arguments and options come from the core `Console\Definitions`; new options are only added. Output is not a contract except the exit codes and the `--json` shape of the core formatter. |
| **Container bindings** listed in [extending.md](extending.md) | Each abstract stays bound to an implementation of the same interface; rebinding and decorating stay possible. |
| **Facade** `Facades\IndexNowKit` and **`IndexNowManager`** (`kit()`, `rules()`, `observe()`, `submit()`, `submitModel()`, `submitModels()`, `urlsFor()`, `urlsForAll()`, `explain()`, `collect()`, `flush()`) | Method names and parameter names stay; new parameters are appended with defaults. |
| **Trait `Eloquent\IndexNowable`**, **`Eloquent\IndexNowObserver`**, **`Eloquent\RouteBindingFieldsInterface`** | The trait stays a drop-in; the observer's public hooks keep their names; the interface gets no new method without a major. |
| **Queue job** `Queue\SubmitUrlsJob` | Its constructor and the serialized shape stay, so jobs queued before an upgrade still run after it. |
| **Route** `indexnow.key_file` (or `key_file.route_name`, `{key}.txt`) | Name and shape stay. |
| **Check classes** `Check\QueueCheck`, `Check\EloquentCheck`, `Check\CacheStoreProbe` and the `indexnowkit.check` tag | Names stay; adding a tagged `CheckInterface` keeps working. |

Not a contract: log message texts (their `context` keys are), the exact wording the commands print (exit codes and
levels are), and the service provider's private methods.

## Pinning

`composer require indexnowkit/laravel:^0.8` gets every 0.8.x patch. Read the changelog before a minor.
