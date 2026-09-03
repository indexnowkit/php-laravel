<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\IndexNowKit;

final class SubmitCommand extends Command
{
    protected $signature = 'indexnow:submit
        {urls* : Absolute URLs or paths relative to base_url}
        {--f|force : Ignore the debounce store: re-submit URLs sent within the last debounce.per_url seconds}
        {--dry-run : Log the request instead of sending it}
        {--json : Machine-readable output}';

    protected $description = 'Submit URLs to IndexNow immediately (synchronously, bypassing the queue)';

    public function handle(IndexNowKit $indexNow, SubmitterFactory $submitters, ResultRenderer $renderer): int
    {
        /** @var list<string> $urls */
        $urls = (array) $this->argument('urls');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $submitter = $force || $dryRun ? $submitters->create($force, $dryRun) : $indexNow->submitter;

        return $renderer->results($this, $submitter->submit($urls), (bool) $this->option('json'));
    }
}
