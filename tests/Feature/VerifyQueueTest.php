<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Queue\Job;
use IndexNowKit\Http\Response;
use IndexNowKit\Laravel\Queue\SubmitUrlsJob;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Retry\RetryPolicy;
use IndexNowKit\SubmitterInterface;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * `verify.enabled: true` with `dispatch: queue`: the job submits through the decorated submitter, so the pre-flight
 * runs in the worker; a skipped origin error is not a job failure.
 */
final class VerifyQueueTest extends LaravelTestCase
{
    protected function configOverrides(): array
    {
        return ['dispatch' => 'queue', 'queue' => ['connection' => 'sync'], 'verify' => ['enabled' => true]];
    }

    #[TestDox('the worker verifies: the noindex URL is dropped, the other one sent; check prints no dispatch warning')]
    public function testTheWorkerVerifies(): void
    {
        $this->transport
            ->onGet('https://www.example.com/w1', new Response(200))
            ->onGet('https://www.example.com/w2', new Response(200, '', headers: ['X-Robots-Tag' => 'noindex']));
        $worker = $this->createMock(Job::class);
        $worker->method('attempts')->willReturn(1);
        $worker->expects(self::never())->method('release');
        $worker->expects(self::never())->method('fail');
        $job = new SubmitUrlsJob(['https://www.example.com/w1', 'https://www.example.com/w2'], new RetryPolicy(maxAttempts: 3), 'test');
        $job->setJob($worker);

        $job->handle($this->app->make(SubmitterInterface::class), $this->logger);

        self::assertSame(['https://www.example.com/w1'], $this->sentUrls());
        self::assertContains('https://www.example.com/w2', $this->transport->gets);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->call('indexnow:check', [], $output);
        self::assertStringNotContainsString('verify.enabled with dispatch: sync', $output->fetch());
    }
}
