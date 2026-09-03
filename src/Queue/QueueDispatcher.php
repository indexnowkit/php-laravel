<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Queue;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use IndexNowKit\Config;
use IndexNowKit\Dispatch\DispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * `dispatch: queue`: hands each flushed batch to SubmitUrlsJob on the configured connection/queue.
 */
final class QueueDispatcher implements DispatcherInterface
{
    public function __construct(
        private readonly BusDispatcher $bus,
        private readonly Config $config,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?string $connection = null,
        private readonly ?string $queue = null,
        private readonly int $delay = 0,
    ) {}

    public function dispatch(array $urls): void
    {
        $id = SubmitUrlsJob::newId();
        try {
            $job = new SubmitUrlsJob($urls, $this->config->retryPolicy(), $id);
            if ($this->connection !== null && $this->connection !== '') {
                $job->onConnection($this->connection);
            }
            if ($this->queue !== null && $this->queue !== '') {
                $job->onQueue($this->queue);
            }
            if ($this->delay > 0) {
                $job->delay($this->delay);
            }
            $this->bus->dispatch($job);
            $this->logger->debug('indexnow: {count} URL(s) queued as job {id}', ['count' => \count($urls), 'id' => $id, 'urls' => $this->config->logSample($urls)]);
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot queue {count} URL(s) (job {id}), they are lost: {error}', ['count' => \count($urls), 'id' => $id, 'error' => $e->getMessage(), 'exception' => $e, 'urls' => $this->config->logSample($urls)]);
        }
    }
}
