# Queue, retries, failures

`dispatch: queue` is the default. Every batch the collector flushes (end of a request, artisan command or handled
job) becomes one `IndexNowKit\Laravel\Queue\SubmitUrlsJob` carrying the normalized URLs.

```php
'dispatch' => 'queue',
'queue' => ['connection' => null, 'queue' => null, 'delay' => 0],
'retry' => ['max_attempts' => 3, 'base_delay' => 60, 'multiplier' => 2.0, 'max_delay' => 3600, 'server_error_delay' => 5],
```

## What the worker does

1. `SubmitterInterface::submit($urls)`: debounce, group by host, split by `batch.max_urls`, one request per engine.
2. Retryable outcomes (429, 5xx, network failure) → `release($delay)`. The delay is `Retry-After` when the engine
   sent one, else `base_delay × multiplier^(attempt-1)` for 429 and `server_error_delay × multiplier^(attempt-1)`
   for 5xx/network, capped at `max_delay`. After `max_attempts` the job **fails** with an error naming the count.
3. Final rejections (400, 403, 422) → the job **fails** immediately with the engine answer in the message and a hint
   to run `indexnow:check`. 403 means the key file is not reachable under `https://<host>/<key>.txt`.
4. Anything else finishes the job. URLs that were accepted are recorded in the debounce store, so a released job
   only resends what was rejected.

`$job->tries` equals `retry.max_attempts`; `backoff()` mirrors the 5xx schedule for the case the job throws instead
of releasing.

## Why failures are failures

A 403 is not transient. Recording it in `failed_jobs` is the one place ops already watch; retrying it would hide the
broken key file. `php artisan queue:retry <id>` after fixing the key file re-sends the batch (the URLs are in the
payload).

## Horizon, workers, Octane

- The job is a plain `ShouldQueue` job: Horizon shows it under its connection and queue, tags are the default ones.
- Long-running workers flush the collector after every handled job (`JobProcessed`), so a job that saves models
  submits their URLs in a follow-up batch on the same connection.
- Under Octane the collector is a scoped binding (reset per request) and `terminating` runs per request.

## Synchronous delivery

`dispatch: sync` sends from `app()->terminating()`, after the response was written. No retries: a 429 is logged and
the URLs are lost until the next change. `QUEUE_CONNECTION=sync` with `dispatch: queue` runs the job inline at flush
time; a `release()` on the sync driver retries nothing, so it is a development setting.

## Checking the wiring

`php artisan indexnow:check` prints the connection and queue the job goes to, warns when the connection driver is
`sync`, and fails when the connection does not exist in `config/queue.php`.
