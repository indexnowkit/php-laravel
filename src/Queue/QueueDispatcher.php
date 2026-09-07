<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Queue;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use IndexNowKit\Config;
use IndexNowKit\Dispatch\BatchingDispatcher;
use IndexNowKit\Dispatch\DispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * `dispatch: queue`: hands each flushed batch to SubmitUrlsJob on the configured connection/queue. The batching,
 * the correlation id and the "they are lost" log line are the core's `Dispatch\BatchingDispatcher`; this class is
 * the push onto the Laravel bus.
 */
final class QueueDispatcher implements DispatcherInterface
{
    private readonly BatchingDispatcher $batches;

    public function __construct(
        private readonly BusDispatcher $bus,
        private readonly Config $config,
        LoggerInterface $logger = new NullLogger(),
        private readonly ?string $connection = null,
        private readonly ?string $queue = null,
        private readonly int $delay = 0,
    ) {
        $this->batches = new BatchingDispatcher($this->push(...), $config, $logger, 'job');
    }

    public function dispatch(array $urls): void
    {
        $this->batches->dispatch($urls);
    }

    /**
     * @param list<string> $urls
     */
    private function push(array $urls, string $id): void
    {
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
    }
}
