<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use IndexNowKit\Result;
use IndexNowKit\ResultStatus;
use IndexNowKit\Retry\RetryPolicy;
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
        $results = $submitter->submit($this->urls);
        $retryable = Result::retryableUrls($results);
        if ($retryable !== []) {
            $delay = $this->policy->delayAfter($results, $this->attempts());
            if ($delay === null) {
                $logger->error('indexnow: giving up on {count} URL(s) of job {id} after {attempts} attempt(s)', ['count' => \count($retryable), 'id' => $this->id, 'attempts' => $this->attempts()]);
                $this->fail(new RuntimeException(\sprintf('IndexNow: %d URL(s) still rejected after %d attempt(s) (job %s)', \count($retryable), $this->attempts(), $this->id)));

                return;
            }
            $logger->info('indexnow: {count} URL(s) of job {id} will be retried in {delay}s (attempt {attempts})', ['count' => \count($retryable), 'id' => $this->id, 'delay' => $delay, 'attempts' => $this->attempts()]);
            $this->release($delay);

            return;
        }
        $final = Result::urlsWhere($results, static fn(Result $r): bool => $r->status === ResultStatus::Failed && !$r->retryable);
        if ($final !== []) {
            $reasons = [];
            foreach ($results as $result) {
                if ($result->status === ResultStatus::Failed && !$result->retryable) {
                    $reasons[] = \sprintf('%s %s', $result->engine, $result->httpCode !== null ? (string) $result->httpCode : ($result->reason->value ?? 'failed'));
                }
            }
            $this->fail(new RuntimeException(\sprintf('IndexNow: %d URL(s) rejected permanently (%s), job %s; run "php artisan indexnow:check"', \count($final), implode(', ', array_unique($reasons)), $this->id)));
        }
    }
}
