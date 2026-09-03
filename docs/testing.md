# Testing your integration

The core ships test doubles in `IndexNowKit\Testing` (part of the package, not dev-only): `FakeTransport`,
`ArrayLogger`, `FrozenClock`, `RecordingDispatcher`. Bind them into the container before the first submission.

```php
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Laravel\IndexNowKitServiceProvider;
use IndexNowKit\Testing\{ArrayLogger, FakeTransport};

final class PostPublishingTest extends TestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(TransportInterface::class, $this->transport = new FakeTransport());
        $this->app->instance(IndexNowKitServiceProvider::LOGGER, new ArrayLogger());
        config(['indexnow.dispatch' => 'sync', 'indexnow.debounce.store' => 'memory', 'indexnow.debounce.per_url' => 0]);
    }

    public function test_publishing_a_post_notifies_the_engines(): void
    {
        $this->post('/posts', ['title' => 'Hello'])->assertCreated();   // the batch leaves on terminate, inside ->post()

        self::assertSame(['https://www.example.com/posts/hello'], $this->transport->posts[0]['body']['urlList']);
        self::assertSame('www.example.com', $this->transport->posts[0]['body']['host']);
    }
}
```

Every entry of `$transport->posts` is `['url' => ..., 'json' => ..., 'headers' => ..., 'body' => ...]`, so you assert
on `host`, `key`, `keyLocation` and `urlList` directly. `FakeTransport::onGet($url, $response)` stubs key file
fetches for `indexnow:check`; `willRespond(new Response(429, '', 30))` queues engine answers.

## Without HTTP at all

`IndexNowKit::urlsFor($post)` and `IndexNowKit::explain($post)` resolve the rules of one model without sending;
`ObjectChangeHandler::updatedEvents()` (via `IndexNowKit::kit()->changes()`) tells which rule classified an update as
created / updated / deleted before any URL exists.

## Queue

`Queue::fake()` then `Queue::assertPushed(SubmitUrlsJob::class, fn ($job) => $job->urls === [...])`. To run the
worker logic in a test, call `$job->handle($submitter, $logger)` with a mocked `Illuminate\Contracts\Queue\Job` set
through `$job->setJob($mock)`; `release()` and `fail()` land on the mock.

## Transactions in tests

`RefreshDatabase` / `DatabaseTransactions` wrap each test in a transaction and swap Laravel's transaction manager for
the testing one, which runs `afterCommit` callbacks at the test's wrapping level. Submissions therefore behave as in
production: they leave when your code's own transaction commits.

## dry_run

Outside `production_environments` a missing `INDEXNOW_KEY` switches `dry_run` on: the whole pipeline runs
(normalization, deduplication, grouping, key lookup) and stops before the POST, with the body in the `info` log.
Set `INDEXNOW_KEY` and `INDEXNOW_DRY_RUN=false` in `phpunit.xml` to exercise the transport in tests.

## Conformance

The package's own suite runs the core conformance kits (`IndexNowKit\Testing\Conformance\CoreConformanceTestCase`,
`OrmConformanceTestCase`) against the container on `orchestra/testbench`; `tests/Conformance/*` shows the driver an
adapter implements.
