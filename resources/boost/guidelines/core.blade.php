## IndexNow (indexnowkit/laravel)

This package tells search engines (Bing, Yandex, Naver, Seznam, Yep, and others in the IndexNow registry — not Google)
about new, changed and deleted pages the moment an Eloquent model is committed. IndexNow is a notification, not indexing.

### Conventions

- Declare public pages on the model with repeatable `#[IndexNow]` attributes and add the `IndexNowable` trait; the observer
  submits after the transaction commits. Bulk queries (`Model::query()->update()`, `DB::table()`) fire no model events:
  call `IndexNowKit::submitModels($models)` or `php artisan indexnow:submit-model` afterwards.
- Configuration is `config/indexnow.php` (`php artisan vendor:publish --tag=indexnow-config`) and `INDEXNOW_*` env variables:
  `INDEXNOW_KEY` (from `php artisan indexnow:key:generate --write-env`), `INDEXNOW_BASE_URL`, `INDEXNOW_DRY_RUN`.
- `dispatch` is `queue` (default) | `sync` | `none` — there is no `auto` in Laravel. Locales for `locales: 'all'` come from
  `indexnow.router.locales`.
- `url:` names an accessor returning a URL; `urls:` lists literal URLs. A string in `when:` is a truthy accessor; compare a
  status with `when: new \IndexNowKit\Attribute\Param\Equals('status', 'published')`.
- Two classes are called `IndexNowKit`: the facade `IndexNowKit\Laravel\Facades\IndexNowKit` and the core service
  `IndexNowKit\IndexNowKit` (inject by type). Import one, alias the other.
- Outside `production_environments` a configured key with `INDEXNOW_DRY_RUN` unset makes `indexnow:check` fail on purpose:
  set `INDEXNOW_DRY_RUN=1` on staging, or `INDEXNOW_DRY_RUN=0` when that environment submits intentionally.

### Declaring a model

@verbatim
<code-snippet name="Model with IndexNow rules" lang="php">
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};
use IndexNowKit\Laravel\Eloquent\IndexNowable;

#[IndexNowDefaults(when: 'isPublished', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'posts.show', params: ['post' => 'self'])]   // route model binding
#[IndexNow(via: 'category')]                                   // the category page changes too
#[IndexNow(urls: ['/'])]                                       // and the homepage
class Post extends Model
{
    use IndexNowable;

    public function isPublished(): bool { return (bool) $this->published; }
}
</code-snippet>
@endverbatim

### Verifying

- `php artisan indexnow:check` validates the configuration, fetches the key file, reports queue, cache and observers; exit 1 on
  any error. `--live` sends one real probe.
- `php artisan indexnow:explain App\Models\Post 1` shows which rule produced which URL and why others were skipped.
- Tests: bind `IndexNowKit\Testing\FakeTransport` as `IndexNowKit\Http\TransportInterface` and assert on `$transport->posts`;
  see `docs/testing.md` of the package.
