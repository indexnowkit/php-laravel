<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\Event;
use IndexNowKit\Exception\InvalidArgumentException;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Url\ResolvedUrl;

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

    public function handle(IndexNowKit $indexNow, ModelLoader $models, SubmitterFactory $submitters, ResultRenderer $renderer): int
    {
        $json = (bool) $this->option('json');
        $modelArg = $this->argument('model');
        try {
            $class = $models->resolveClass(\is_string($modelArg) ? $modelArg : '');
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }
        $eventOption = $this->option('event');
        $event = Event::tryFrom(\is_string($eventOption) ? $eventOption : '');
        if ($event === null) {
            $this->error('--event must be created, updated or deleted.');

            return self::INVALID;
        }
        /** @var list<string> $ids */
        $ids = array_map(strval(...), (array) $this->argument('ids'));
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : 1000;
        $withTrashed = $event === Event::Deleted;
        if ($ids === []) {
            $entities = [...$models->all($class, $limit, $withTrashed)];
            if (\count($entities) >= $limit && !$json) {
                $this->warn(\sprintf('--limit=%d reached: models beyond the first %d were not loaded.', $limit, $limit));
            }
        } else {
            [$entities, $missing] = $models->byIds($class, $ids, $withTrashed);
            if ($missing !== []) {
                $this->error(\sprintf('%s: id(s) not found: %s', $class, implode(', ', $missing)));
                if ($entities === []) {
                    return self::INVALID;
                }
            }
        }

        $resolved = [];
        foreach ($entities as $entity) {
            $resolved = [...$resolved, ...$indexNow->explain($entity, $event)];
        }
        $urls = ResolvedUrl::urls($resolved);
        if (!$json) {
            $this->line(\sprintf('%d model%s -> %d URL(s)', \count($entities), \count($entities) === 1 ? '' : 's', \count($urls)));
        }
        if ((bool) $this->option('explain')) {
            return $this->explain($resolved, $json);
        }
        if ($urls === [] && !$json) {
            $this->line('No URL resolved: no #[IndexNow] rule applies to these models for this event (run with --explain, or php artisan indexnow:explain <model> <id>).');
        }
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $submitter = $force || $dryRun ? $submitters->create($force, $dryRun) : $indexNow->submitter;

        return $renderer->results($this, $submitter->submit($urls), $json);
    }

    /**
     * @param list<ResolvedUrl> $resolved
     */
    private function explain(array $resolved, bool $json): int
    {
        if ($json) {
            $this->getOutput()->writeln((string) json_encode(array_map(static fn(ResolvedUrl $r): array => ['class' => $r->class, 'rule' => $r->rule, 'event' => $r->event->value, 'locale' => $r->locale, 'url' => $r->url], $resolved), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($resolved === []) {
            $this->warn('No URL resolved.');

            return self::SUCCESS;
        }
        $this->table(['class', 'rule', 'event', 'locale', 'url'], array_map(static fn(ResolvedUrl $r): array => [$r->class, $r->rule, $r->event->value, $r->locale ?? '-', $r->url], $resolved));

        return self::SUCCESS;
    }
}
