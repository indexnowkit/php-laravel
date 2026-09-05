<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\Console\Definitions;
use IndexNowKit\Console\ExplainRunner;
use IndexNowKit\Console\Vocabulary;

/**
 * "Why was this model not submitted?" Walks the decision path of one model: rules -> event subscription -> `when`
 * guard -> resolved URLs -> normalization -> host/key -> debounce. Sends nothing.
 */
final class ExplainCommand extends Command
{
    public function __construct(Vocabulary $words)
    {
        $definition = Definitions::explain($words, 'model');
        $this->signature = $definition->laravelSignature('indexnow:explain');
        $this->description = $definition->description;
        parent::__construct();
    }

    public function handle(ExplainRunner $runner): int
    {
        $model = $this->argument('model');
        $id = $this->argument('id');
        $event = $this->option('event');

        return $runner->run($this->getOutput(), \is_string($model) ? $model : '', \is_scalar($id) ? (string) $id : '', \is_string($event) ? $event : '', (bool) $this->option('json'));
    }
}
