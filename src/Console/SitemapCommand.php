<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Sitemap\Console\SitemapOptions;
use IndexNowKit\Sitemap\Console\SitemapRunner;
use IndexNowKit\Sitemap\SitemapConfig;

/**
 * Streams a sitemap (or sitemap index) and submits it in batches of `batch.max_urls`. The source is whatever the
 * container binds to SitemapSourceInterface (the shipped SitemapReader, or the application's replacement).
 */
final class SitemapCommand extends Command
{
    protected $signature = 'indexnow:sitemap
        {sitemap? : Sitemap URL or local file (default: sitemap.url from the config, else <base_url>/sitemap.xml)}
        {--changed-since= : Only URLs whose <lastmod> is newer, e.g. "1 day" or "2026-09-01"}
        {--allow-foreign-hosts : Follow nested sitemaps hosted on another origin (CDN) for this run}
        {--f|force : Ignore the debounce store}
        {--dry-run : List URLs without submitting}
        {--json : Machine-readable output}';

    protected $description = 'Submit every URL of a sitemap (or only those with lastmod after --changed-since)';

    public function handle(SitemapRunner $runner, SitemapConfig $config): int
    {
        if (!$config->enabled) {
            $this->getOutput()->error('sitemap.enabled is false.');

            return ExitCode::INVALID;
        }
        $sitemap = $this->argument('sitemap');
        $since = $this->option('changed-since');

        return $runner->run($this->getOutput(), new SitemapOptions(
            sitemap : \is_string($sitemap) ? $sitemap : null,
            changedSince : \is_string($since) ? $since : null,
            allowForeignHosts : (bool) $this->option('allow-foreign-hosts'),
            force : (bool) $this->option('force'),
            dryRun : (bool) $this->option('dry-run'),
            json : (bool) $this->option('json'),
        ));
    }
}
