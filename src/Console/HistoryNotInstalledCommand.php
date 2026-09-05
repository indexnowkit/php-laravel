<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\Console\ExitCode;

/**
 * `indexnow:history` while `indexnowkit/history` is not installed: a sentence and exit 1 instead of "command not
 * found" (a scheduler entry or a runbook that names the command keeps a readable answer). Every option is accepted
 * and ignored.
 */
final class HistoryNotInstalledCommand extends Command
{
    protected $signature = 'indexnow:history';

    protected $description = 'List the recorded IndexNow submissions (needs indexnowkit/history, which is not installed)';

    /**
     * @param string $message what to print: `OptionalPackage::notInstalledMessage()` of the provider's history package
     */
    public function __construct(private readonly string $message)
    {
        parent::__construct();
        $this->ignoreValidationErrors();
    }

    public function handle(): int
    {
        $this->getOutput()->writeln('<error>' . $this->message . '</error>'); // one line: a scheduler log greps it

        return ExitCode::FAILURE;
    }
}
