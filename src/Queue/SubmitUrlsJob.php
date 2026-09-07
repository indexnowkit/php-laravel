<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
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
 *
 * `retry.max_attempts` bounds the batch, not the single queue message: a partially accepted batch comes back as a
 * new job (`release()` would replay the whole payload), and a new job's `attempts()` starts at one again, so the
 * attempts already spent travel with it in {@see $spentAttempts}. {@see attempt()} is the number every decision and
 * every log line uses.
 */
final class SubmitUrlsJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    /**
     * @param list<string> $urls          normalized absolute URLs
     * @param string       $id            correlation id shared by the dispatch and worker log lines
     * @param int          $spentAttempts attempts this batch already used up in the jobs before this one (0 for the first)
     */
    public function __construct(public readonly array $urls, public readonly RetryPolicy $policy, public readonly string $id, public readonly int $spentAttempts = 0)
    {
        $this->tries = max(1, $policy->maxAttempts - $spentAttempts);
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(6));
    }

    /** 1-based number of the attempt this batch is making now, across every job it has been re-queued as. */
    public function attempt(): int
    {
        return $this->spentAttempts + $this->attempts();
    }

    /**
     * Backoff Laravel applies when the job throws; releases from handle() carry their own delay. The batch's own
     * attempt numbering continues, so a re-queued job does not start the curve over.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        $delays = [];
        for ($attempt = $this->spentAttempts + 1; $attempt < $this->policy->maxAttempts; ++$attempt) {
            $delays[] = max(0, min($this->policy->maxDelay, (int) round($this->policy->serverErrorDelay * $this->policy->multiplier ** ($attempt - 1))));
        }

        return $delays === [] ? [0] : $delays;
    }

    public function handle(SubmitterInterface $submitter, LoggerInterface $logger, ?BusDispatcher $bus = null): void
    {
        $outcome = WorkerOutcome::of($submitter->submit($this->urls));
        if ($outcome->hasRetryable()) {
            $attempt = $this->attempt();
            $delay = $outcome->delay($this->policy, $attempt);
            if ($delay === null) {
                $logger->error(...$outcome->gaveUpLog($this->id, $attempt));
                $this->fail(new RuntimeException(\sprintf('IndexNow: %d URL(s) still rejected after %d attempt(s) (job %s)', \count($outcome->retryUrls), $attempt, $this->id)));

                return;
            }
            $logger->info(...$outcome->retryLog($this->id, $delay, $attempt));
            if ($bus !== null && \count($outcome->retryUrls) < \count($this->urls)) {
                // Part of the batch went through: only the rest comes back, as its own job — release() would replay the
                // whole payload. The attempts spent so far travel with it, so max_attempts bounds the batch.
                $next = new self($outcome->retryUrls, $this->policy, $this->id, $attempt);
                $next->onConnection($this->connection)->onQueue($this->queue)->delay($delay);
                $bus->dispatch($next);

                return;
            }
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
