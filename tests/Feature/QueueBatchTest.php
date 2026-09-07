<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Queue;
use IndexNowKit\Http\Response;
use IndexNowKit\Laravel\Queue\SubmitUrlsJob;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Retry\RetryPolicy;
use IndexNowKit\SubmitterInterface;
use PHPUnit\Framework\Attributes\TestDox;

/** `batch.max_urls: 1` with the queue: one job per chunk on flush, and a retry that re-queues only what was rejected. */
final class QueueBatchTest extends LaravelTestCase
{
    protected function configOverrides(): array
    {
        return ['dispatch' => 'queue', 'queue' => ['connection' => 'sync', 'queue' => 'seo'], 'batch' => ['max_urls' => 1], 'retry' => ['max_attempts' => 3, 'base_delay' => 60, 'server_error_delay' => 5]];
    }

    #[TestDox('flush pushes one job per batch.max_urls URLs')]
    public function testOneJobPerChunk(): void
    {
        Queue::fake();
        $this->kit()->collect(['https://www.example.com/a', 'https://www.example.com/b', 'https://www.example.com/c']);
        $this->kit()->flush();

        Queue::assertPushed(SubmitUrlsJob::class, 3);
    }

    #[TestDox('a batch accepted in part is re-queued as a smaller job with the rejected URLs only; release() is not used')]
    public function testPartialRetryDispatchesTheRestOnly(): void
    {
        $this->transport->willRespond(new Response(200), new Response(429, '', 7));
        $worker = $this->createMock(Job::class);
        $worker->method('attempts')->willReturn(1);
        $worker->expects(self::never())->method('release');
        $job = new SubmitUrlsJob(['https://www.example.com/ok', 'https://www.example.com/later'], new RetryPolicy(maxAttempts: 3, baseDelay: 60, serverErrorDelay: 5), 'job1');
        $job->setJob($worker);
        $job->onQueue('seo');
        $dispatched = null;
        $bus = $this->createMock(BusDispatcher::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $next) use (&$dispatched): mixed {
            $dispatched = $next;

            return null;
        });

        $job->handle($this->app->make(SubmitterInterface::class), $this->logger, $bus);

        self::assertInstanceOf(SubmitUrlsJob::class, $dispatched);
        self::assertSame(['https://www.example.com/later'], $dispatched->urls);
        self::assertSame('job1', $dispatched->id);
        self::assertSame('seo', $dispatched->queue);
        self::assertSame(7, $dispatched->delay, 'Retry-After of the engine');
        self::assertSame(1, $dispatched->spentAttempts, 'the attempt just made travels with the re-queued job');
        self::assertSame(2, $dispatched->tries, 'and what is left of retry.max_attempts');
    }

    #[TestDox('retry.max_attempts bounds the batch, not each re-queued job: the last attempt fails instead of re-queueing again')]
    public function testMaxAttemptsBoundsTheWholeBatch(): void
    {
        $policy = new RetryPolicy(maxAttempts: 2, baseDelay: 60, serverErrorDelay: 5);
        $this->transport->willRespond(new Response(200), new Response(429, '', 7), new Response(429, '', 7));

        // First job: two URLs, one accepted, one rejected -> a second job for the rest, carrying the spent attempt.
        $first = new SubmitUrlsJob(['https://www.example.com/ok', 'https://www.example.com/later'], $policy, 'job2');
        $first->setJob($this->worker(1));
        $requeued = null;
        $first->handle($this->app->make(SubmitterInterface::class), $this->logger, $this->busCollecting($requeued));

        self::assertInstanceOf(SubmitUrlsJob::class, $requeued);
        self::assertSame(1, $requeued->spentAttempts);
        self::assertSame(1, $requeued->tries, 'one attempt left of retry.max_attempts: 2');

        // Second job: a fresh queue message, so attempts() is 1 again — the batch is nevertheless out of attempts.
        $worker = $this->createMock(Job::class);
        $worker->method('attempts')->willReturn(1);
        $worker->expects(self::never())->method('release');
        $worker->expects(self::once())->method('fail');
        $requeued->setJob($worker);
        $requeued->handle($this->app->make(SubmitterInterface::class), $this->logger, $this->busExpectingNothing());

        self::assertSame(2, $requeued->attempt());
        self::assertStringContainsString('giving up on 1 URL(s) of job job2 after 2 attempt(s)', implode("\n", $this->logger->messages('error')));
    }

    private function worker(int $attempts): Job
    {
        $worker = $this->createMock(Job::class);
        $worker->method('attempts')->willReturn($attempts);

        return $worker;
    }

    private function busCollecting(?SubmitUrlsJob &$captured): BusDispatcher
    {
        $bus = $this->createMock(BusDispatcher::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $next) use (&$captured): mixed {
            \assert($next instanceof SubmitUrlsJob);
            $captured = $next;

            return null;
        });

        return $bus;
    }

    private function busExpectingNothing(): BusDispatcher
    {
        $bus = $this->createMock(BusDispatcher::class);
        $bus->expects(self::never())->method('dispatch');

        return $bus;
    }
}
