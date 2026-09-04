<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Sitemap\Console\Definitions;
use IndexNowKit\Sitemap\Console\SitemapOptions;
use IndexNowKit\Sitemap\Console\SitemapRunner;
use IndexNowKit\Sitemap\SitemapConfig;

/**
 * Streams a sitemap (or sitemap index) and submits it in batches of `batch.max_urls`. The source is whatever the
 * container binds to SitemapSourceInterface (the shipped SitemapReader, or the application's replacement).
 */
final class SitemapCommand extends Command
{
    public function __construct()
    {
        $definition = Definitions::sitemap('indexnow.sitemap.url');
        $this->signature = $definition->laravelSignature('indexnow:sitemap');
        $this->description = $definition->description;
        parent::__construct();
    }

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
