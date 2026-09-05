<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\Job;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\History\Psr16SubmissionStore;
use IndexNowKit\Laravel\Queue\SubmitUrlsJob;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Retry\RetryPolicy;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\SubmitterInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `history.store: psr16` with `dispatch: queue`: the job's submission lands in the ring buffer of the cache store,
 * `status` names the queue connection and queue, `check` describes the store.
 */
final class HistoryQueueTest extends LaravelTestCase
{
    protected function configOverrides(): array
    {
        return ['dispatch' => 'queue', 'queue' => ['connection' => 'sync', 'queue' => 'seo'], 'history' => ['store' => 'psr16', 'limit' => 10]];
    }

    #[TestDox('the worker records into the psr16 store; history, status and check see it')]
    public function testTheWorkerRecords(): void
    {
        self::assertInstanceOf(Psr16SubmissionStore::class, $this->app->make(SubmissionStoreInterface::class));
        $worker = $this->createMock(Job::class);
        $worker->method('attempts')->willReturn(1);
        $job = new SubmitUrlsJob(['https://www.example.com/w1', 'https://www.example.com/w2'], new RetryPolicy(maxAttempts: 3), 'test');
        $job->setJob($worker);

        $job->handle($this->app->make(SubmitterInterface::class), $this->logger);
        self::assertCount(2, $this->sentUrls());

        [$code, $output] = $this->artisanCall('indexnow:history', ['--json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded['records']);
        self::assertSame(['https://www.example.com/w1', 'https://www.example.com/w2'], $decoded['records'][0]['urls']);

        [$code, $output] = $this->artisanCall('indexnow:status', ['--json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        HistoryTest::assertStatusFollowsTheSchema($decoded);
        self::assertSame(['mode' => 'queue', 'adapter' => ['connection' => 'sync', 'queue' => 'seo']], $decoded['dispatch']);
        self::assertSame('history', $decoded['history']['store']);
        self::assertSame(1, $decoded['history']['records']);
        self::assertSame(2, $decoded['history']['last_success']['urls']);

        [, $output] = $this->artisanCall('indexnow:check');
        self::assertStringContainsString('history: psr16 store (10 records kept)', $output);
        self::assertMatchesRegularExpression('/history: 1 records?, last \d+ s ago/', $output);

        [, $output] = $this->artisanCall('about', ['--only' => 'indexnow']);
        self::assertStringContainsString('psr16 (10 records kept), 1 records', $output);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function artisanCall(string $command, array $arguments = []): array
    {
        $output = new BufferedOutput();
        $code = $this->app->make(Kernel::class)->call($command, $arguments, $output);

        return [$code, $output->fetch()];
    }
}
