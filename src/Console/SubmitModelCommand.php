<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\Console\Definitions;
use IndexNowKit\Console\SubmitSubjectsOptions;
use IndexNowKit\Console\SubmitSubjectsRunner;
use IndexNowKit\Console\Vocabulary;

final class SubmitModelCommand extends Command
{
    public function __construct(Vocabulary $words)
    {
        $definition = Definitions::submitSubjects($words, 'model');
        $this->signature = $definition->laravelSignature('indexnow:submit-model');
        $this->description = $definition->description;
        parent::__construct();
    }

    public function handle(SubmitSubjectsRunner $runner): int
    {
        $model = $this->argument('model');
        $event = $this->option('event');
        $limit = $this->option('limit');

        return $runner->run($this->getOutput(), new SubmitSubjectsOptions(
            class : \is_string($model) ? $model : '',
            ids : array_values(array_map(\strval(...), (array) $this->argument('ids'))),
            event : \is_string($event) ? $event : '',
            limit : is_numeric($limit) ? (int) $limit : 1000,
            explain : (bool) $this->option('explain'),
            force : (bool) $this->option('force'),
            dryRun : (bool) $this->option('dry-run'),
            json : (bool) $this->option('json'),
        ));
    }
}
