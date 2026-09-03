<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use BackedEnum;
use Closure;
use Illuminate\Console\Command;
use IndexNowKit\Attribute\Param\Equals;
use IndexNowKit\Attribute\UrlRule;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Event;
use IndexNowKit\Exception\InvalidArgumentException;
use IndexNowKit\Exception\InvalidUrlException;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Url\UrlNormalizerInterface;
use Throwable;

/**
 * "Why was this model not submitted?" Walks the decision path of one model: rules -> event subscription -> `when`
 * guard -> resolved URLs -> normalization -> host/key -> debounce. Sends nothing.
 */
final class ExplainCommand extends Command
{
    protected $signature = 'indexnow:explain
        {model : Model class (FQCN or App\Models short name)}
        {id : Identifier}
        {--event=updated : created | updated | deleted}';

    protected $description = 'Explain what IndexNow would do for one model: rules, guards, URLs, key, debounce (sends nothing)';

    public function handle(IndexNowKit $indexNow, ModelLoader $models, Config $config, KeyProviderInterface $keys, DebounceStoreInterface $debounce, UrlNormalizerInterface $normalizer): int
    {
        $modelArg = $this->argument('model');
        $idArg = $this->argument('id');
        $id = \is_scalar($idArg) ? (string) $idArg : '';
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
        [$found] = $models->byIds($class, [$id], $event === Event::Deleted);
        if ($found === []) {
            $this->error(\sprintf('%s with id "%s" not found.', $class, $id));

            return self::INVALID;
        }
        $model = $found[0];

        $this->line(\sprintf('<options=bold>IndexNow explain: %s #%s (%s)</>', $class, $id, $event->value));
        $this->line('  enabled:  ' . ($config->enabled ? 'yes' : 'NO (enabled: false): nothing is sent'));
        $this->line('  dry_run:  ' . ($config->dryRun ? 'yes: requests are logged, not sent' : 'no'));
        $this->line('  dispatch: ' . $config->dispatch);
        $this->line('  debounce: ' . $config->debouncePerUrl . 's');

        $rules = $indexNow->changes()->rulesOf($model);
        if ($rules->isEmpty()) {
            $this->line('  <fg=red>✘</> no #[IndexNow] rule on ' . $class . ' (or the attribute is invalid: see the log)');

            return self::FAILURE;
        }
        $urls = [];
        foreach ($rules as $rule) {
            $urls = [...$urls, ...$this->explainRule($indexNow, $model, $rule, $event)];
        }
        if ($urls === []) {
            $this->newLine();
            $this->warn('No URL would be submitted for this event.');

            return self::SUCCESS;
        }
        $this->newLine();
        $this->line('<options=bold>Delivery</>');
        foreach (array_unique($urls) as $url) {
            $this->explainUrl($url, $config, $keys, $debounce, $normalizer);
        }
        $this->newLine();
        $this->line('Nothing was sent. Submit with: php artisan indexnow:submit-model ' . $class . ' ' . $id);

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function explainRule(IndexNowKit $indexNow, object $model, UrlRule $rule, Event $event): array
    {
        $this->newLine();
        $this->line(\sprintf('<options=bold>Rule "%s" (%s%s)</>', $rule->name, $rule->source->value, $rule->route !== null ? ' ' . $rule->route : ''));
        $this->line(\sprintf('  events: %s -> %s', implode(', ', array_map(static fn(Event $e): string => $e->value, $rule->events)), $rule->listensTo($event) ? '<fg=green>subscribed</>' : '<fg=yellow>not subscribed to ' . $event->value . '</>'));
        if ($rule->when !== []) {
            $conditions = implode(' && ', array_map(self::describeCondition(...), $rule->when));
            try {
                $applies = $rule->appliesTo($model);
                $this->line(\sprintf('  when: %s -> %s', $conditions, $applies ? '<fg=green>true</>' : '<fg=yellow>false (page not public, nothing submitted)</>'));
            } catch (Throwable $e) {
                $this->line(\sprintf('  when: %s -> <fg=red>error: %s</>', $conditions, $e->getMessage()));

                return [];
            }
        }
        if ($rule->fields !== []) {
            $this->line(\sprintf('  fields: updates count only when one of [%s] changed', implode(', ', $rule->fields)));
        }
        $resolved = $indexNow->resolver()->resolveRule($model, $rule, $event);
        if ($resolved === []) {
            $this->line('  urls: <fg=yellow>none</> (see above, or the indexnow log channel for resolver errors)');

            return [];
        }
        $urls = [];
        foreach ($resolved as $item) {
            $this->line(\sprintf('  url: <fg=green>%s</>%s%s', $item->url, $item->locale !== null ? ' [' . $item->locale . ']' : '', $item->rule !== $rule->name ? ' via ' . $item->rule : ''));
            $urls[] = $item->url;
        }

        return $urls;
    }

    private static function describeCondition(mixed $condition): string
    {
        return match (true) {
            \is_string($condition) => $condition,
            $condition instanceof Equals => \sprintf('%s == %s', $condition->path, json_encode($condition->value instanceof BackedEnum ? $condition->value->value : $condition->value)),
            $condition instanceof Closure => 'closure',
            default => get_debug_type($condition),
        };
    }

    private function explainUrl(string $url, Config $config, KeyProviderInterface $keys, DebounceStoreInterface $debounce, UrlNormalizerInterface $normalizer): void
    {
        try {
            $normalized = $normalizer->normalize($url);
        } catch (InvalidUrlException $e) {
            $this->line(\sprintf('  %s -> <fg=red>dropped: %s</>', $url, $e->getMessage()));

            return;
        }
        $host = $normalizer->hostOf($normalized);
        $key = $keys->keyFor($host);
        $line = '  ' . $normalized;
        if ($normalized !== $url) {
            $line .= ' (normalized from ' . $url . ')';
        }
        if ($key === null) {
            $this->line($line . \sprintf(' -> <fg=red>skipped: no key for host %s</> (add it to "hosts" or set base_url)', $host));

            return;
        }
        $keyFile = $keys->keyLocationFor($host) ?? \sprintf('https://%s/%s.txt', $host, $key);
        $line .= \sprintf(' -> host %s, key %s (%s)', $host, KeyValidator::mask($key), str_replace($key, KeyValidator::mask($key), $keyFile));
        if ($config->debouncePerUrl > 0) {
            try {
                $recent = $debounce->filterRecent([$normalized], $config->debouncePerUrl) !== [];
                $line .= $recent ? \sprintf(', <fg=yellow>debounced</> (sent within the last %ds; indexnow:submit --force bypasses)', $config->debouncePerUrl) : ', not debounced';
            } catch (Throwable $e) {
                $line .= ', debounce store unavailable (' . $e->getMessage() . '), would submit';
            }
        }
        $this->line($line);
    }
}
