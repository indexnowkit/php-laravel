<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\Console\Definitions;
use IndexNowKit\Console\SubmitRunner;

final class SubmitCommand extends Command
{
    public function __construct()
    {
        $definition = Definitions::submit();
        $this->signature = $definition->laravelSignature('indexnow:submit');
        $this->description = $definition->description;
        parent::__construct();
    }

    public function handle(SubmitRunner $runner): int
    {
        /** @var list<string> $urls */
        $urls = array_values(array_map(\strval(...), (array) $this->argument('urls')));

        return $runner->run($this->getOutput(), $urls, (bool) $this->option('force'), (bool) $this->option('dry-run'), (bool) $this->option('json'));
    }
}
