<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Database\DatabaseManager;
use IndexNowKit\History\Pdo\Schema;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Submission\SubmissionRecord;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\Testing\FrozenClock;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Throttle\TokenBucket;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;

/**
 * `Psr\Clock\ClockInterface` is a replaceable binding, and the pieces that read time take it: the submission
 * timestamps of the history store, the debounce window and the throttle. Binding `Testing\FrozenClock` makes all
 * three deterministic.
 */
final class ClockBindingTest extends LaravelTestCase
{
    private FrozenClock $clock;

    protected function configOverrides(): array
    {
        return ['history' => ['store' => 'pdo'], 'debounce' => ['per_url' => 600, 'store' => 'memory']];
    }

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('2026-09-07 08:30:00');
        $this->afterApplicationCreated(function (): void {
            $this->app->instance(ClockInterface::class, $this->clock);
        });
        parent::setUp();
    }

    #[TestDox('the provider binds a system clock by default and the application can replace it')]
    public function testTheBindingIsReplaceable(): void
    {
        self::assertSame($this->clock, $this->app->make(ClockInterface::class));
        self::assertInstanceOf(TokenBucket::class, $this->app->make(ThrottleInterface::class));
    }

    #[TestDox('a submission is recorded at the bound clock time, not at the system time')]
    public function testHistoryRecordCarriesTheBoundClock(): void
    {
        $this->createHistoryTable();

        $this->kit()->submit(['https://www.example.com/timed']);

        $records = [...$this->app->make(SubmissionStoreInterface::class)->recent(10)];
        self::assertCount(1, $records);
        self::assertInstanceOf(SubmissionRecord::class, $records[0]);
        self::assertSame('2026-09-07T08:30:00+00:00', $records[0]->at->format(DATE_ATOM));
    }

    #[TestDox('the debounce window is measured on the bound clock: advancing it past debounce.per_url reopens the URL')]
    public function testDebounceWindowFollowsTheBoundClock(): void
    {
        $url = 'https://www.example.com/debounced';

        $this->kit()->submit([$url]);
        self::assertCount(1, $this->transport->posts);

        $this->kit()->submit([$url]);
        self::assertCount(1, $this->transport->posts, 'still inside debounce.per_url');

        $this->clock->advance(601);
        $this->kit()->submit([$url]);
        self::assertCount(2, $this->transport->posts, 'the window is over on the bound clock');
    }

    private function createHistoryTable(): void
    {
        $pdo = $this->app->make(DatabaseManager::class)->connection()->getPdo();
        foreach (Schema::sql('sqlite') as $sql) {
            $pdo->exec($sql);
        }
    }
}
