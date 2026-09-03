<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\Console\SubmitRunner;

final class SubmitCommand extends Command
{
    protected $signature = 'indexnow:submit
        {urls* : Absolute URLs or paths relative to base_url}
        {--f|force : Ignore the debounce store: re-submit URLs sent within the last debounce.per_url seconds}
        {--dry-run : Log the request instead of sending it}
        {--json : Machine-readable output}';

    protected $description = 'Submit URLs to IndexNow immediately (synchronously, bypassing the queue)';

    public function handle(SubmitRunner $runner): int
    {
        /** @var list<string> $urls */
        $urls = array_values(array_map(\strval(...), (array) $this->argument('urls')));

        return $runner->run($this->getOutput(), $urls, (bool) $this->option('force'), (bool) $this->option('dry-run'), (bool) $this->option('json'));
    }
}
