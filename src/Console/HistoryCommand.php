<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\History\Console\Definitions;
use IndexNowKit\History\Console\HistoryOptions;
use IndexNowKit\History\Console\HistoryRunner;

/**
 * The recorded submissions of the `SubmissionStoreInterface` binding (the store of `history.store`, or the
 * application's own), newest first; `--purge` runs the retention of the package's stores.
 */
final class HistoryCommand extends Command
{
    public function __construct()
    {
        $definition = Definitions::history();
        $this->signature = $definition->laravelSignature('indexnow:history');
        $this->description = $definition->description;
        parent::__construct();
    }

    public function handle(HistoryRunner $runner): int
    {
        $limit = $this->option('limit');
        // `{--purge=}`: absent = no purge; `--purge` alone = the configured retention; `--purge=30` = 30 days.
        $purge = $this->input->hasParameterOption('--purge') ? ($this->option('purge') ?? true) : null;

        return $runner->run($this->getOutput(), new HistoryOptions(
            host: self::str($this->option('host')),
            status: self::str($this->option('status')),
            url: self::str($this->option('url')),
            since: self::str($this->option('since')),
            limit: \is_string($limit) || \is_int($limit) ? $limit : 50,
            json: (bool) $this->option('json'),
            purge: \is_string($purge) || \is_bool($purge) ? $purge : null,
        ));
    }

    private static function str(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
