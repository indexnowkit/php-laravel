# Laravel-пакет IndexNow — `indexnowkit/laravel`

Сообщайте поисковым системам о новых, изменённых и удалённых страницах в момент, когда Eloquent-модель закоммичена.
Один атрибут на модели, одна переменная окружения — готово.

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/laravel)](https://packagist.org/packages/indexnowkit/laravel)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/laravel)](https://packagist.org/packages/indexnowkit/laravel)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
[![Conformance](https://img.shields.io/badge/conformance-core%2022%2F22%20%C2%B7%20orm%2021%2F21%20%C2%B7%20http%206%2F6-brightgreen)](https://github.com/indexnowkit/spec)
![PHPStan](https://img.shields.io/badge/phpstan-level%209-4c1)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4) ![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20)
[![License](https://img.shields.io/packagist/l/indexnowkit/laravel)](LICENSE)

[English version](README.md) · Issues и pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (репозитории `php-*` — read-only сплиты)

## Кого уведомляем

**Яндекс, Bing (и DuckDuckGo через Bing), Naver, Seznam, Yep, Internet Archive, Amazon** — все участники
[реестра](https://www.indexnow.org/searchengines.json) протокола [IndexNow](https://www.indexnow.org). Один запрос на
общий endpoint доходит до всех; перечислять движки явно нужно только чтобы отправить в один.

**Google — нет.** Google не поддерживает IndexNow, пинг sitemap отключён (404), а Indexing API ограничен
`JobPosting` / `BroadcastEvent`. Пакет не будет делать вид, что это не так.

**Уведомление, не индексация.** IndexNow сообщает поисковику, что URL изменился; обойти и проиндексировать страницу — его
решение и его сроки. Результат виден в Bing Webmaster Tools (IndexNow Insights) и в Яндекс.Вебмастере (Индексирование →
Переобход страниц); полезная метрика — доля отправленных URL в индексе через несколько дней. Удалённые страницы: отдавайте
410 (навсегда) или 404 (временно); при переезде — 301 и отправка обоих URL; soft-404 и редирект на главную вредят.
Bing URL Submission API и Google Indexing API — другие протоколы, здесь не покрываются.

## Почему это, а не X

Большинство пакетов IndexNow — тонкий HTTP-клиент: URL собираете вы, вызываете вы, ответ читаете вы. Это семейство делает
то, что на практике ломается:

- **Объявлено на модели** (`#[IndexNow]`) и отправляется из хуков ORM — нет кода в контроллере, который можно забыть.
- **После commit**, не на flush: откатившаяся транзакция ничего не объявляет.
- **Дебаунс** (10 минут на URL, через ваш кэш), **батчи** до 10 000 URL, ключ на host из env.
- **Ответы обработаны**: 202 (ключ проверяется), 422, 429 с `Retry-After` и повтором через вашу очередь, эскалация 403.
- **`check` до первой отправки** говорит, что не так (файл ключа, движки, очередь, кэш, окружение); `explain` — почему URL ушёл или не ушёл.
- **Одно ядро** под адаптерами Symfony, Laravel, Yii2 и Doctrine с общим conformance-набором: поведение одинаковое везде и описано один раз.


## Установка

```bash
composer require indexnowkit/laravel
composer require indexnowkit/sitemap               # опционально: команда indexnow:sitemap
php artisan vendor:publish --tag=indexnow-config   # config/indexnow.php (необязательно: у каждого ключа есть дефолт)
php artisan indexnow:key:generate --write-env      # добавит INDEXNOW_KEY в .env
php artisan indexnow:check                         # конфиг, доступность файла ключа, очередь, кэш
```

Провайдер подхватывается auto-discovery. Laravel поставляется с Guzzle — это PSR-18 клиент, который пакет находит
сам; любой другой PSR-18 клиент тоже подойдёт (`indexnow.http.client`).

```dotenv
INDEXNOW_KEY=...                      # из key:generate
INDEXNOW_BASE_URL=https://www.example.com   # по умолчанию APP_URL; нужен artisan-командам и воркерам очереди
```

## Объявите, у чего есть публичная страница

`#[IndexNow]` повторяемый: один атрибут на семейство публичных URL модели. `IndexNowable` регистрирует observer.

```php
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};
use IndexNowKit\Laravel\Eloquent\IndexNowable;

#[IndexNowDefaults(when: 'isPublished', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(route: 'posts.show', params: ['post' => 'self'])]                 // route model binding
#[IndexNow(route: 'posts.amp', params: ['post' => 'self'], when: 'hasAmp')]
#[IndexNow(via: 'category')]      // изменённый пост обновляет и страницу своей категории
#[IndexNow(urls: ['/'])]          // и главную
class Post extends Model
{
    use IndexNowable;

    public function isPublished(): bool { return $this->published; }
    public function hasAmp(): bool { return $this->amp; }
}
```

| Опция | Смысл |
|---|---|
| `route` / `params` | имя маршрута и `param => атрибут, метод, "self", путь.через.точку` либо типизированный `Param\*` |
| `resolver` | класс или binding `UrlResolverInterface` для чего угодно нестандартного |
| `via` | отношение (или путь), страницы которого переотправляются |
| `url` / `urls` | метод, возвращающий URL, либо литеральные URL |
| `when` / `whenFields` | bool-атрибут или метод; черновики пропускаются, `published → draft` уходит как удаление |
| `fields` | при обновлении отправлять только если изменился один из этих атрибутов |
| `events` | подмножество `created`, `updated`, `deleted` |
| `locales` | `current` (по умолчанию), `all` (`indexnow.router.locales`) или список |
| `host` | генерировать URL правила на другом хосте (мультидомен) |
| `name` | стабильный id правила для логов, `indexnow:explain` и переопределения в наследнике |

Accessor'ы читают атрибуты, cast'ы, аксессоры и отношения Eloquent (`category.slug`), а затем методы
(`isPublished()`). `params: ['post' => 'self']` передаёт модель в `route()`: работают и `{post}`, и `{post:slug}`.
Атрибут `when`, у которого есть только дефолт **в базе**, на модели сразу после `create()` отсутствует — задайте
дефолт на модели (`protected $attributes = ['published' => false]`).

Полная модель, типизированные параметры, наследование и таблица семантики:
[справочник атрибута в core](https://github.com/indexnowkit/php/blob/main/packages/core/docs/attribute-reference.md).

### Модели, которые нельзя аннотировать

```php
// AppServiceProvider::boot()
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults, RuleSet};
use IndexNowKit\Laravel\Facades\IndexNowKit;

IndexNowKit::observe(Product::class, [new IndexNow(route: 'products.show', params: ['product' => 'self'])], new IndexNowDefaults(when: 'is_active'));
IndexNowKit::rules()->registerFor(Page::class, fn (Page $page): ?RuleSet => ...);   // решение по объекту
```

Классов с именем `IndexNowKit` два. **Фасад** `IndexNowKit\Laravel\Facades\IndexNowKit` (выше) проксирует `IndexNowManager`
пакета: `observe()`, `rules()`, `submitModel()`, `submitModels()`, `submit()`, `collect()`, `flush()`, `explain()`.
**Ядро** `IndexNowKit\IndexNowKit` — тот же сервис без Eloquent-специфики; его инжектят по типу
(`public function __construct(private IndexNowKit $indexNow)`) или берут из фасада через `IndexNowKit::kit()`.
Импортируйте в файле один из них, второму дайте alias.

## Проверка

```bash
php artisan indexnow:check          # конфиг, файл ключа, движки, connection очереди, cache-стор, spool
php artisan indexnow:check --live   # плюс реальный пробный запрос в каждый движок
```

Запускайте после каждой ротации ключа и каждого деплоя, который трогает конфигурацию.

## Как это работает

- Observer резолвит URL, **пока живо старое состояние** (`getOriginal()` в `updated`, строка в `deleting`), и
  передаёт их через `Connection::afterCommit()`: ничто не уходит до коммита внешней транзакции, откат транзакции
  (или savepoint) их отбрасывает. Вложенность `DB::transaction()` обрабатывает менеджер транзакций Laravel.
- Каждое правило классифицируется отдельно: страница статьи может быть обновлением, а AMP-страница той же модели —
  удалением, в одном запросе.
- Всё собранное за один HTTP-запрос, artisan-команду или job уходит **одним батчем** в `app()->terminating()` (или
  после каждой обработанной job), никогда внутри вашего запроса.
- `dispatch: queue` (по умолчанию) кладёт `SubmitUrlsJob`; 429 и 5xx повторяются с backoff, `Retry-After`
  главнее, 403/422 проваливают job — сломанный файл ключа виден в `failed_jobs`. `QUEUE_CONNECTION=sync`
  выполняет её inline.
- `SoftDeletes`: soft delete — удаление, `restore()` — создание, `forceDelete()` — удаление.
- Переименованная страница (сменился slug или ключ маршрута за `self`) объявляет старый URL удалённым, новый —
  обновлённым, в одном батче.
- Ничто из правила, резолвера или HTTP-слоя не долетает до приложения: пишется в лог, сохранение проходит.

## Команды

| Команда | Опции |
|---|---|
| `indexnow:check` | `--live` реальный пробник · `--host=` один хост · `--probe-url=` страница для пробника |
| `indexnow:submit <urls...>` | `-f, --force` игнорировать дебаунс · `--dry-run` · `--json` |
| `indexnow:submit-model <model> [ids...]` | `--event=` · `--limit=` · `--explain` · `-f, --force` · `--dry-run` · `--json` |
| `indexnow:explain <model> <id>` | `--event=` — правила, `when`, URL, ключ, дебаунс; ничего не отправляет |
| `indexnow:sitemap [sitemap]` | `--changed-since="1 day"` · `--allow-foreign-hosts` · `-f, --force` · `--dry-run` · `--json` |
| `indexnow:key:generate` | `-l, --length` · `--alphanumeric` · `--write-env[=FILE]` (по умолчанию `.env`) · `--force` ротация |

`<model>` — FQCN или короткое имя в `App\Models`.

### Sitemap

`composer require indexnowkit/sitemap   # опционально: команда indexnow:sitemap`

`indexnow:sitemap` без аргумента читает `indexnow.sitemap.url`, иначе `<base_url>/sitemap.xml`; локальный путь тоже
работает. Планировщик: `Schedule::command('indexnow:sitemap --changed-since="1 day"')->daily()`. Без пакета всё
остальное работает как прежде: `indexnow:sitemap` отвечает `indexnowkit/sitemap is not installed: composer require
indexnowkit/sitemap` и завершается с кодом 1, `indexnow:check` печатает `sitemap: not installed (…)`, блок `sitemap`
в `config/indexnow.php` игнорируется. В логи ничего не пишется. Подробнее: [docs/sitemap.md](docs/sitemap.md).

## Конфигурация

Каждый ключ `config/indexnow.php`, его дефолт и назначение: [docs/configuration.md](docs/configuration.md).

| Тема | |
|---|---|
| Очередь, повторы, Horizon | [docs/queue.md](docs/queue.md) |
| Несколько доменов и локали | [docs/multi-domain.md](docs/multi-domain.md) |
| Sitemap | [docs/sitemap.md](docs/sitemap.md) |
| Расширение: заменяемые binding'и, свои резолверы и проверки | [docs/extending.md](docs/extending.md) |
| Тестирование интеграции | [docs/testing.md](docs/testing.md) |
| Диагностика | [docs/troubleshooting.md](docs/troubleshooting.md) |

## Отладка

1. **`php artisan indexnow:explain "App\Models\Post" 42`** проходит весь путь решения для одной модели — правила,
   подписка на событие, `when`, `fields`, URL, нормализация, host и ключ, дебаунс — и ничего не отправляет.
2. **Канал лога** (`indexnow.logging.channel`, иначе дефолтный) содержит всё; на `debug` — и причину, по которой
   правило *не* дало URL. Сообщения и уровни:
   [operations guide](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md).
3. **`failed_jobs`** хранит батчи, которые движок отверг окончательно (403: файл ключа недоступен).

Невалидная конфигурация не бросает из сохранения: IndexNow выключается, пишется одна строка `critical`, точную
ошибку печатает `indexnow:check`.

## Ограничения

- `Model::query()->update()`, `delete()`, `insert()`, `upsert()` и `DB::table()` не вызывают событий модели (A13):
  после них — `IndexNowKit::submitModels($query->get())` или `php artisan indexnow:submit-model`.
- `attach()` / `detach()` / `sync()` на pivot не вызывают событий владельца. Поставьте `$touches = ['posts']` на
  связанной модели: `updated` владельца (изменился только `updated_at`) доходит до правила без фильтра `fields`.
- `dispatch: sync` зависит от `terminating`. Под Octane оно срабатывает; ранний `exit()` или фатальная ошибка
  теряют батч с предупреждением. Предпочитайте дефолтный `queue`.
- Поддомены — отдельные хосты: дайте каждому свой ключ в `hosts` и включите `strict_hosts: true`.
- Вне production (`production_environments`, по умолчанию `prod`/`production`) отсутствующий `INDEXNOW_KEY`
  включает `dry_run`, а не падает.

## Совместимость

Публичный API: ключи `config/indexnow.php`, имена и опции команд, binding'и контейнера из
[docs/extending.md](docs/extending.md), `Facades\IndexNowKit` / `IndexNowManager`, `Eloquent\IndexNowable`,
`Queue\SubmitUrlsJob`. Действуют правила core, включая интерфейсы «may grow»:
[bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md); что стабильно в самом пакете: [docs/bc.md](docs/bc.md). До 1.0 минорная версия может ломать
совместимость; каждое изменение перечислено в «Changed» в [CHANGELOG.md](CHANGELOG.md) с миграцией. Laravel 12 и 13,
PHP 8.2–8.5 (Laravel 13 требует PHP 8.3).

## Другие фреймворки

| | |
|---|---|
| PHP | [core](https://github.com/indexnowkit/php/tree/main/packages/core), [symfony-bundle](https://github.com/indexnowkit/php/tree/main/packages/symfony-bundle), [doctrine](https://github.com/indexnowkit/php/tree/main/packages/doctrine), [laravel](https://github.com/indexnowkit/php/tree/main/packages/laravel), [yii2](https://github.com/indexnowkit/php/tree/main/packages/yii2) |
| JS/TS | @indexnowkit/core, next, prisma (скоро) |
| Python | indexnowkit, indexnowkit-django (скоро) |

Обоснование решений: [docs/spec](https://github.com/indexnowkit/php/tree/main/docs/spec). История изменений: [CHANGELOG.md](CHANGELOG.md).

MIT. IndexNow — торговая марка её владельца; проект независим и не связан с Microsoft, Яндексом или indexnow.org.
