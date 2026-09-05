<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\Console\ExitCode;

/**
 * `indexnow:sitemap` while `indexnowkit/sitemap` is not installed: a scheduled run that used the command before
 * the package went optional gets a sentence and exit 1 instead of "command not found". Every argument and option
 * of the real command is accepted and ignored.
 */
final class SitemapNotInstalledCommand extends Command
{
    protected $signature = 'indexnow:sitemap {sitemap?}';

    protected $description = 'Submit every URL of a sitemap (needs indexnowkit/sitemap, which is not installed)';

    /**
     * @param string $message what to print: `OptionalPackage::notInstalledMessage()` of the provider's sitemap package
     */
    public function __construct(private readonly string $message)
    {
        parent::__construct();
        $this->ignoreValidationErrors();
    }

    public function handle(): int
    {
        $this->getOutput()->writeln('<error>' . $this->message . '</error>'); // one line, not a wrapped block: a scheduler log greps it

        return ExitCode::FAILURE;
    }
}
