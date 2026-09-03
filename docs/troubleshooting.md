# Troubleshooting

Start with `php artisan indexnow:check`, then `php artisan indexnow:explain "App\Models\Post" <id>`, then the log
channel at `debug`.

## Nothing is sent

| Symptom | Cause | Fix |
|---|---|---|
| `check`: `configuration: ...` and exit 1 | an `env()` value is invalid; IndexNow runs disabled | fix the value; the exact error is printed |
| `explain`: `when: published -> false` right after `create()` | the `when` attribute only has a database default, so the fresh model does not have it | `protected $attributes = ['published' => false]` on the model, or set it explicitly |
| `explain`: `no #[IndexNow] rule` | the model has no attribute and was not registered | add the attribute, or `IndexNowKit::observe()` |
| URLs resolved but no POST | `dispatch: queue` and no worker | `php artisan queue:work`, or `dispatch: sync` |
| log: `rule "..." ignores this update (fields ...)` | `fields` filter did not match the changed attributes | add the attribute to `fields`, or drop the filter |
| log: `Cannot generate route "posts.show": Missing required parameter` | the rule's `params` do not match the route | `params: ['post' => 'self']` for route model binding, or name every parameter |
| log: `Cannot read "foo" on App\Models\Post: no property, getter or method found` | typo in an accessor | fix the accessor; attributes, casts, accessors, relations and methods are all valid |
| a mass `update()` changed nothing in the index | bulk statements fire no events (A13) | `IndexNowKit::submitModels($query->get())` or `indexnow:submit-model` |
| `attach()` on a pivot changed nothing | pivot operations fire no owner events | `$touches = ['posts']` on the related model, rule without `fields` filter |

## Sent, but the engine answers

| Answer | Meaning | Fix |
|---|---|---|
| 403 (`invalid_key`, job failed) | `https://<host>/<key>.txt` is not reachable or has another body | `indexnow:check`; a CDN may cache the old file (`key_file.cache_max_age`) |
| 422 (`unprocessable`) | URLs of another host than `host`, or key file on another host | one key per host (`hosts`), `strict_hosts: true` |
| 429 (`rate_limited`) | too many requests | the job releases with `Retry-After`; lower `throttle.max_requests_per_minute` |
| 202 (`pending`) | accepted, key verification pending | normal for a new key; `check --live` later answers 200 |

## Duplicates, timing

- The same URL is not resubmitted within `debounce.per_url` (600 s). `--force` bypasses it; `debounce.store: cache`
  shares the window between requests and workers, `memory` does not.
- Everything from one request leaves as one batch on `terminating`; a job saving models flushes after the job.
- A rolled-back transaction submits nothing; a savepoint rollback inside `DB::transaction()` drops only the inner
  URLs.

## Testing environments

Outside `production_environments` a missing key enables `dry_run`: requests are logged, not sent. `check` warns
about it; in a production environment `dry_run` is an error.

## Where things are logged

Channel `indexnow.logging.channel` (default channel otherwise). Levels: success `debug`, 202 `info`, 403/400
`error`, 422/429/5xx `warning`, invalid configuration `critical`, silent decisions (`when` false, `fields` mismatch)
`debug`. The full list is in the
[core operations guide](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md).
