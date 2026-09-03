<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use DateTimeImmutable;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Sitemap\SitemapEntry;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\SitemapSourceInterface;

/**
 * Reads a sitemap (or sitemap index) as a stream and submits it in batches of `batch.max_urls`, so the URL list
 * never has to fit in memory. The source is whatever the container binds to SitemapSourceInterface (the shipped
 * SitemapReader, or the application's replacement); `--allow-foreign-hosts` only reaches the shipped reader.
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

    public function handle(IndexNowKit $indexNow, SitemapSourceInterface $reader, SubmitterFactory $submitters, ResultRenderer $renderer, Repository $config): int
    {
        $json = (bool) $this->option('json');
        $sitemap = $this->sitemapUrl($indexNow, $config);
        if ($sitemap === null) {
            $this->error('Give a sitemap URL, or configure indexnow.sitemap.url or base_url.');

            return self::INVALID;
        }
        try {
            $since = $this->changedSince();
        } catch (Exception $e) {
            $this->error(\sprintf('--changed-since: %s', $e->getMessage()));

            return self::INVALID;
        }
        $allowForeignHosts = (bool) $this->option('allow-foreign-hosts') ? true : null;
        if ($allowForeignHosts === true && !$reader instanceof SitemapReader) {
            $this->warn(\sprintf('--allow-foreign-hosts is an option of the shipped SitemapReader; the configured source (%s) decides on its own.', $reader::class));
        }
        $entries = $reader instanceof SitemapReader ? $reader->read($sitemap, $since, $allowForeignHosts) : $reader->read($sitemap, $since);
        $found = 0;

        if ((bool) $this->option('dry-run')) {
            try {
                $found = $json ? $this->listJson($entries) : $this->listText($entries);
            } catch (TransportException $e) {
                $this->error(\sprintf('Cannot read %s: %s', $sitemap, $e->getMessage()));

                return self::FAILURE;
            }
            if (!$json) {
                $this->line(self::foundLine($found, $sitemap, $since));
            }

            return self::SUCCESS;
        }

        $submitter = (bool) $this->option('force') ? $submitters->create(true, false) : $indexNow->submitter;
        $batchSize = max(1, $indexNow->config->batchMaxUrls);
        $summary = new ResultSummary();
        $batch = [];
        $batches = 0;
        try {
            foreach ($entries as $entry) {
                ++$found;
                $batch[] = $entry->url;
                if (\count($batch) >= $batchSize) {
                    $summary->add($submitter->submit($batch));
                    $batch = [];
                    ++$batches;
                    if (!$json && $this->getOutput()->isVerbose()) {
                        $this->line(\sprintf('  batch %d: %d URL(s) read so far', $batches, $found));
                    }
                }
            }
        } catch (TransportException $e) {
            // Whatever was read before the failure is still worth announcing; the re-run is idempotent anyway.
            if ($batch !== []) {
                $summary->add($submitter->submit($batch));
                ++$batches;
            }
            $error = \sprintf('Cannot read %s: %s', $sitemap, $e->getMessage());
            if ($json) {
                // stdout stays machine-readable: the partial summary as JSON, the error on stderr.
                $this->getOutput()->getErrorStyle()->error($error);
                $renderer->summary($this, $summary, true);

                return self::FAILURE;
            }
            $this->error($error);
            if ($batches > 0) {
                $this->line(\sprintf('%d URL(s) read before the error were submitted in %d batch(es); re-run the command once the sitemap is reachable.', $found, $batches));
                $renderer->summary($this, $summary, false);
            }

            return self::FAILURE;
        }
        if ($batch !== []) {
            $summary->add($submitter->submit($batch));
        }
        if (!$json) {
            $this->line(self::foundLine($found, $sitemap, $since));
        }

        return $renderer->summary($this, $summary, $json);
    }

    private function sitemapUrl(IndexNowKit $indexNow, Repository $config): ?string
    {
        $argument = $this->argument('sitemap');
        if (\is_string($argument) && $argument !== '') {
            return $argument;
        }
        $default = $config->get('indexnow.sitemap.url');
        if (\is_string($default) && $default !== '') {
            return $default;
        }
        $baseUrl = $indexNow->config->baseUrl;

        return $baseUrl === null ? null : rtrim($baseUrl, '/') . '/sitemap.xml';
    }

    /**
     * @throws Exception on an unparseable value
     */
    private function changedSince(): ?DateTimeImmutable
    {
        $option = $this->option('changed-since');
        if (!\is_string($option) || $option === '') {
            return null;
        }

        return new DateTimeImmutable(preg_match('/^\d+\s*\w+$/', $option) === 1 ? '-' . $option : $option);
    }

    private static function foundLine(int $found, string $sitemap, ?DateTimeImmutable $since): string
    {
        return \sprintf('%d URL(s) found in %s%s', $found, $sitemap, $since !== null ? ' changed since ' . $since->format(DATE_ATOM) : '');
    }

    /**
     * @param iterable<SitemapEntry> $entries
     */
    private function listText(iterable $entries): int
    {
        $found = 0;
        foreach ($entries as $entry) {
            ++$found;
            $this->line(' * ' . $entry->url);
        }

        return $found;
    }

    /**
     * Streams a JSON array of URLs, one element per line, without holding the list.
     *
     * @param iterable<SitemapEntry> $entries
     */
    private function listJson(iterable $entries): int
    {
        $found = 0;
        $output = $this->getOutput();
        $output->write('[');
        foreach ($entries as $entry) {
            $output->write(($found === 0 ? "\n    " : ",\n    ") . json_encode($entry->url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            ++$found;
        }
        $output->writeln($found === 0 ? ']' : "\n]");

        return $found;
    }
}
