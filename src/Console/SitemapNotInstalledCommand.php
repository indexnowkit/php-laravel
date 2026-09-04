<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Laravel\Sitemap\SitemapSupport;

/**
 * `indexnow:sitemap` while `indexnowkit/sitemap` is not installed: a scheduled run that used the command before
 * the package went optional gets a sentence and exit 1 instead of "command not found". Every argument and option
 * of the real command is accepted and ignored.
 */
final class SitemapNotInstalledCommand extends Command
{
    protected $signature = 'indexnow:sitemap {sitemap?}';

    protected $description = 'Submit every URL of a sitemap (needs indexnowkit/sitemap, which is not installed)';

    public function __construct()
    {
        parent::__construct();
        $this->ignoreValidationErrors();
    }

    public function handle(): int
    {
        $this->getOutput()->writeln('<error>' . SitemapSupport::NOT_INSTALLED . '</error>'); // one line, not a wrapped block: a scheduler log greps it

        return ExitCode::FAILURE;
    }
}
