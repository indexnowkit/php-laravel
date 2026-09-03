<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\Console\SubmitSubjectsOptions;
use IndexNowKit\Console\SubmitSubjectsRunner;

final class SubmitModelCommand extends Command
{
    protected $signature = 'indexnow:submit-model
        {model : Model class (FQCN or App\Models short name)}
        {ids?* : Identifiers; none = every model of the class up to --limit}
        {--event=updated : created | updated | deleted}
        {--limit=1000 : Max models when no ids are given}
        {--explain : Show which rule produced which URL and submit nothing}
        {--f|force : Ignore the debounce store}
        {--dry-run : Log the request instead of sending it}
        {--json : Machine-readable output}';

    protected $description = 'Resolve the URLs of Eloquent models through their #[IndexNow] rules and submit them (the manual path after bulk updates)';

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
