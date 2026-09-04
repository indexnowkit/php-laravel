<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use IndexNowKit\Retry\RetryPolicy;
use IndexNowKit\Retry\WorkerOutcome;
use IndexNowKit\SubmitterInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Submits one batch of URLs from a queue worker. Retryable outcomes (429, 5xx, network) release the job with the
 * delay RetryPolicy computes (Retry-After wins) until `retry.max_attempts`; final failures (400, 403, 422) fail
 * the job without retry, so a broken key file shows up in failed_jobs. Successful URLs are debounced, so a
 * released job only resends what was rejected.
 */
final class SubmitUrlsJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    /**
     * @param list<string> $urls normalized absolute URLs
     * @param string       $id   correlation id shared by the dispatch and worker log lines
     */
    public function __construct(public readonly array $urls, public readonly RetryPolicy $policy, public readonly string $id)
    {
        $this->tries = $policy->maxAttempts;
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(6));
    }

    /**
     * Backoff Laravel applies when the job throws; releases from handle() carry their own delay.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        $delays = [];
        for ($attempt = 1; $attempt < $this->policy->maxAttempts; ++$attempt) {
            $delays[] = max(0, min($this->policy->maxDelay, (int) round($this->policy->serverErrorDelay * $this->policy->multiplier ** ($attempt - 1))));
        }

        return $delays === [] ? [0] : $delays;
    }

    public function handle(SubmitterInterface $submitter, LoggerInterface $logger): void
    {
        $outcome = WorkerOutcome::of($submitter->submit($this->urls));
        if ($outcome->hasRetryable()) {
            $delay = $outcome->delay($this->policy, $this->attempts());
            if ($delay === null) {
                $logger->error(...$outcome->gaveUpLog($this->id, $this->attempts()));
                $this->fail(new RuntimeException(\sprintf('IndexNow: %d URL(s) still rejected after %d attempt(s) (job %s)', \count($outcome->retryUrls), $this->attempts(), $this->id)));

                return;
            }
            $logger->info(...$outcome->retryLog($this->id, $delay, $this->attempts()));
            $this->release($delay);

            return;
        }
        if ($outcome->hasFinalFailures()) {
            [$message, $context] = $outcome->finalLog($this->id, 'php artisan indexnow:check');
            $logger->error($message, $context);
            $this->fail(new RuntimeException(\sprintf('IndexNow: %d URL(s) rejected permanently (%s), job %s; run "php artisan indexnow:check"', \count($outcome->finalUrls), implode(', ', $outcome->finalReasons), $this->id)));
        }
    }
}
