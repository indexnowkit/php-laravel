<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Queue;
use IndexNowKit\Http\Response;
use IndexNowKit\Laravel\Queue\SubmitUrlsJob;
use IndexNowKit\Laravel\Tests\Fixtures\Post;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Retry\RetryPolicy;
use IndexNowKit\SubmitterInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use Throwable;

final class QueueDispatchTest extends LaravelTestCase
{
    protected function configOverrides(): array
    {
        return ['dispatch' => 'queue', 'queue' => ['connection' => 'sync', 'queue' => 'seo', 'delay' => 3], 'retry' => ['max_attempts' => 3, 'base_delay' => 60, 'server_error_delay' => 5]];
    }

    #[TestDox('A14 dispatch: queue -> flush pushes one SubmitUrlsJob with the batch, on the configured connection and queue')]
    public function testFlushQueuesOneJob(): void
    {
        Queue::fake();
        Post::query()->create(['slug' => 'q1']);
        Post::query()->create(['slug' => 'q2']);
        $this->kit()->flush();

        Queue::assertPushed(SubmitUrlsJob::class, 1);
        Queue::assertPushedOn('seo', SubmitUrlsJob::class);
        Queue::assertPushed(SubmitUrlsJob::class, static fn(SubmitUrlsJob $job): bool => $job->urls === ['https://www.example.com/posts/q1', 'https://www.example.com/posts/q2'] && $job->connection === 'sync' && $job->delay === 3 && $job->tries === 3);
        self::assertSame([], $this->transport->posts, 'nothing sent synchronously');
    }

    #[TestDox('the worker sends the batch; a 200 finishes the job')]
    public function testJobSubmits(): void
    {
        [$job, $worker] = $this->job(['https://www.example.com/w1'], attempt: 1);
        $worker->expects(self::never())->method('release');
        $worker->expects(self::never())->method('fail');

        $job->handle($this->app->make(SubmitterInterface::class), $this->logger);

        self::assertSame(['https://www.example.com/w1'], $this->sentUrls());
    }

    #[TestDox('429 with Retry-After -> released with that delay; without it the RetryPolicy backoff applies')]
    public function testRateLimitedIsReleased(): void
    {
        $this->transport->willRespond(new Response(429, '', 30), new Response(429));
        $submitter = $this->app->make(SubmitterInterface::class);

        [$job, $worker] = $this->job(['https://www.example.com/r1'], attempt: 1);
        $worker->expects(self::once())->method('release')->with(30);
        $job->handle($submitter, $this->logger);

        [$job, $worker] = $this->job(['https://www.example.com/r1'], attempt: 2);
        $worker->expects(self::once())->method('release')->with(120);
        $job->handle($submitter, $this->logger);
        self::assertSame([5, 10], $job->backoff());
    }

    #[TestDox('retryable outcome on the last allowed attempt -> the job fails with a "giving up" error')]
    public function testGivesUpAfterMaxAttempts(): void
    {
        $this->transport->willRespond(new Response(500));
        [$job, $worker] = $this->job(['https://www.example.com/r2'], attempt: 3);
        $worker->expects(self::never())->method('release');
        $worker->expects(self::once())->method('fail')->with(self::callback(static fn(Throwable $e): bool => str_contains($e->getMessage(), 'after 3 attempt(s)')));

        $job->handle($this->app->make(SubmitterInterface::class), $this->logger);

        self::assertStringContainsString('giving up', implode("\n", $this->logger->messages('error')));
    }

    #[TestDox('403 / 422 are final: the job fails without retry and names the engine answer')]
    public function testForbiddenFails(): void
    {
        $this->transport->willRespond(new Response(403));
        [$job, $worker] = $this->job(['https://www.example.com/f1'], attempt: 1);
        $worker->expects(self::never())->method('release');
        $worker->expects(self::once())->method('fail')->with(self::callback(static fn(Throwable $e): bool => str_contains($e->getMessage(), '403') && str_contains($e->getMessage(), 'indexnow:check')));

        $job->handle($this->app->make(SubmitterInterface::class), $this->logger);
    }

    #[TestDox('QUEUE_CONNECTION=sync runs the job inline at flush time')]
    public function testSyncConnectionRunsInline(): void
    {
        Post::query()->create(['slug' => 'inline']);
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/inline'], $this->sentUrls());
    }

    /**
     * @param list<string> $urls
     *
     * @return array{0: SubmitUrlsJob, 1: Job&MockObject}
     */
    private function job(array $urls, int $attempt): array
    {
        $worker = $this->createMock(Job::class);
        $worker->method('attempts')->willReturn($attempt);
        $job = new SubmitUrlsJob($urls, new RetryPolicy(maxAttempts: 3, baseDelay: 60, serverErrorDelay: 5), 'test');
        $job->setJob($worker);

        return [$job, $worker];
    }
}
