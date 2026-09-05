<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\History\Console\Definitions;
use IndexNowKit\History\Console\StatusRunner;

/**
 * Read-only: switches, dispatch (and the queue connection and name), the debounce store, the 403 counter of every
 * host, the last successful submission, the history size. Nothing is fetched.
 */
final class StatusCommand extends Command
{
    public function __construct()
    {
        $definition = Definitions::status();
        $this->signature = $definition->laravelSignature('indexnow:status');
        $this->description = $definition->description;
        parent::__construct();
    }

    public function handle(StatusRunner $runner): int
    {
        return $runner->run($this->getOutput(), (bool) $this->option('json'));
    }
}
