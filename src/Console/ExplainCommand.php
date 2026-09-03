<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\Console\ExplainRunner;

/**
 * "Why was this model not submitted?" Walks the decision path of one model: rules -> event subscription -> `when`
 * guard -> resolved URLs -> normalization -> host/key -> debounce. Sends nothing.
 */
final class ExplainCommand extends Command
{
    protected $signature = 'indexnow:explain
        {model : Model class (FQCN or App\Models short name)}
        {id : Identifier}
        {--event=updated : created | updated | deleted}';

    protected $description = 'Explain what IndexNow would do for one model: rules, guards, URLs, key, debounce (sends nothing)';

    public function handle(ExplainRunner $runner): int
    {
        $model = $this->argument('model');
        $id = $this->argument('id');
        $event = $this->option('event');

        return $runner->run($this->getOutput(), \is_string($model) ? $model : '', \is_scalar($id) ? (string) $id : '', \is_string($event) ? $event : '');
    }
}
