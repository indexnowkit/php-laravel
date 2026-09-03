<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Check;

use Illuminate\Contracts\Config\Repository;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;

/**
 * `dispatch: queue` needs a queue connection that exists; the `sync` driver works but retries nothing.
 */
final class QueueCheck implements CheckInterface
{
    public function __construct(private readonly Repository $config) {}

    public function check(CheckReport $report): void
    {
        $dispatch = $this->config->get('indexnow.dispatch');
        if ($dispatch !== 'queue') {
            $report->ok(\sprintf('dispatch "%s": URLs are %s', \is_string($dispatch) ? $dispatch : 'sync', $dispatch === 'none' ? 'collected but never sent (drain the collector yourself)' : 'sent synchronously when the request or command terminates; 429/5xx are not retried'));

            return;
        }
        $connection = $this->config->get('indexnow.queue.connection');
        $connection = \is_string($connection) && $connection !== '' ? $connection : $this->config->get('queue.default');
        $connection = \is_string($connection) ? $connection : 'sync';
        $settings = $this->config->get('queue.connections.' . $connection);
        if (!\is_array($settings)) {
            $report->error(\sprintf('queue: connection "%s" is not defined in config/queue.php; SubmitUrlsJob cannot be queued.', $connection));

            return;
        }
        $driver = $settings['driver'] ?? null;
        $queue = $this->config->get('indexnow.queue.queue');
        $target = \sprintf('connection "%s" (%s)%s', $connection, \is_string($driver) ? $driver : '?', \is_string($queue) && $queue !== '' ? ', queue "' . $queue . '"' : '');
        if ($driver === 'sync') {
            $report->warning(\sprintf('queue: %s runs SubmitUrlsJob inline; 429/5xx are not retried. Use a real queue driver in production.', $target));

            return;
        }
        $report->ok(\sprintf('queue: SubmitUrlsJob goes to %s; run a worker (php artisan queue:work) or nothing is sent', $target));
    }
}
