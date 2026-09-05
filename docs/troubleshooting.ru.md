# Диагностика

[English version](troubleshooting.md)

Начните с `php artisan indexnow:check`, затем `php artisan indexnow:explain "App\Models\Post" <id>`, затем канал лога на
`debug`.

## Не отправляется ничего

| Симптом | Причина | Исправление |
|---|---|---|
| `check`: `configuration: ...` и exit 1 | значение из `env()` невалидно; IndexNow работает выключенным | исправьте значение; точная ошибка напечатана |
| `explain`: `when: published -> false` сразу после `create()` | у атрибута `when` дефолт только в базе, свежая модель его не имеет | `protected $attributes = ['published' => false]` на модели, или задайте явно |
| `explain`: `no #[IndexNow] rule` | у модели нет атрибута и она не зарегистрирована | добавьте атрибут или `IndexNowKit::observe()` |
| URL разрешены, POST нет | `dispatch: queue` и нет воркера | `php artisan queue:work` или `dispatch: sync` |
| лог: `rule "..." ignores this update (fields ...)` | фильтр `fields` не совпал с изменёнными атрибутами | добавьте атрибут в `fields` или уберите фильтр |
| лог: `Cannot generate route "posts.show": Missing required parameter` | `params` правила не совпадают с маршрутом | `params: ['post' => 'self']` для route model binding, либо назовите каждый параметр |
| лог: `Cannot read "foo" on App\Models\Post: no method foo(), getFoo(), isFoo() or hasFoo(), no property "foo"…` | опечатка в аксессоре | исправьте аксессор; годятся атрибуты, casts, accessors, relations и методы |
| массовый `update()` ничего не изменил в индексе | bulk-запросы не вызывают событий (A13) | `IndexNowKit::submitModels($query->get())` или `indexnow:submit-model` |
| `attach()` на pivot ничего не изменил | операции с pivot не дают событий владельцу | `$touches = ['posts']` на связанной модели, правило без фильтра `fields` |

## Отправлено, но движок отвечает

| Ответ | Смысл | Исправление |
|---|---|---|
| 403 (`invalid_key`, job failed) | `https://<host>/<key>.txt` недоступен или с другим телом | `indexnow:check`; CDN может кэшировать старый файл (`key_file.cache_max_age`) |
| 422 (`unprocessable`) | URL другого хоста, чем `host`, или файл ключа на другом хосте | ключ на каждый хост (`hosts`), `strict_hosts: true` |
| 429 (`rate_limited`) | слишком много запросов | job освобождается с `Retry-After`; понизьте `throttle.max_requests_per_minute` |
| 202 (`pending`) | принято, проверка ключа в процессе | нормально для нового ключа; `check --live` позже ответит 200 |

Счётчик 403, эскалирующий в `critical`, — на процесс: несколько воркеров считают каждый свои пять.

## Дубли, тайминги

- Тот же URL не отправляется повторно в течение `debounce.per_url` (600 с). `--force` обходит это;
  `debounce.store: cache` делит окно между запросами и воркерами, `memory` — нет.
- Всё из одного запроса уходит одним батчем на `terminating`; job, сохраняющий модели, сбрасывает после job.
- Откатившаяся транзакция ничего не отправляет; откат savepoint внутри `DB::transaction()` отбрасывает только
  внутренние URL.

## Стейджинг отправил свои URL

| Симптом | Причина | Исправление |
|---|---|---|
| Bing/Яндекс показывают URL `staging.example.com`, или в логе `failed` / `unprocessable` (422) по ним | стейджинг работает с боевым ключом и без `INDEXNOW_DRY_RUN`; URL сгенерированы на его хосте | вне production задайте `INDEXNOW_DRY_RUN=1` (или `INDEXNOW_ENABLED=0`); с core 0.6 `check` на такой копии падает |
| стейджинг отдаёт боевой файл ключа | `key_file.enabled` включён везде | `key_file.enabled: false` вне production |
| движки проиндексировали страницы стейджинга | хост стейджинга ответил `200` и отдал ключ | отдавайте `410` (или `noindex` + запрет в `robots.txt`) и ротируйте ключ, если он утёк |
| preview-окружение должно отправлять нарочно | — | `INDEXNOW_DRY_RUN=0` явно; `check` тогда предупреждает, а не падает |

## Дубли с `memory` и несколькими воркерами

| Симптом | Причина | Исправление |
|---|---|---|
| один и тот же URL отправляет каждый воркер | `debounce.store: memory` — на процесс | `debounce.store` = общий store (`cache`); `check` предупреждает о `memory` |
| дубли сразу после сбоя кэша | store fails open | ожидаемо и ограничено; следите за warning `debounce store unavailable` |
| дубли после деплоя | кэш сброшен или изменился `debounce.key_prefix` | безвредно один раз; держите префикс стабильным |

## Тестовые окружения

Вне `production_environments` отсутствующий ключ включает `dry_run`: запросы логируются, не отправляются. `check` об
этом предупреждает; в production `dry_run` — ошибка. Настроенный ключ без `INDEXNOW_DRY_RUN` вне production — ошибка
`check` (стейджинг отправлял бы боевые URL).

## Где логи

Канал `indexnow.logging.channel` (иначе канал по умолчанию). Уровни: успех `debug`, 202 `info`, 403/400 `error`,
422/429/5xx `warning`, невалидная конфигурация `critical`, молчаливые решения (`when` false, несовпадение `fields`)
`debug`. Полный список — в [руководстве по эксплуатации ядра](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md).
