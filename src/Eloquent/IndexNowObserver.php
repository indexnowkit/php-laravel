<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Eloquent;

use Illuminate\Database\Eloquent\Model;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Attribute\RuleSource;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Url\ResolvedUrl;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * Eloquent hooks. Synchronous on purpose (not ShouldHandleEventsAfterCommit): URLs are resolved while the old state
 * is still live — `getOriginal()` in `updated`, the row and its relations in `deleting` — and handed to the
 * collector through Connection::afterCommit(), which Laravel's DatabaseTransactionsManager runs only when the
 * outermost transaction commits and drops when the transaction (or the savepoint it belongs to) rolls back.
 *
 * Nothing here throws into the application: the core's ObjectChangeHandler logs and yields nothing on a bad rule,
 * and every hand-off is guarded.
 */
final class IndexNowObserver
{
    /** Model events the observer handles; {@see IndexNowable} registers exactly these. */
    public const EVENTS = ['created', 'updated', 'deleting', 'deleted', 'restored'];

    /** @var WeakMap<Model, list<string>> URLs resolved in `deleting`, delivered in `deleted` */
    private WeakMap $pendingDeletions;

    public function __construct(
        private readonly IndexNowKit $indexNow,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $enabled = true,
        private readonly ?RouteBindingFieldsInterface $router = null,
    ) {
        $this->pendingDeletions = new WeakMap();
    }

    public function created(Model $model): void
    {
        $this->guard($model, fn(): array => $this->indexNow->changes()->created($model));
    }

    public function updated(Model $model): void
    {
        $this->guard($model, function () use ($model): array {
            $changeSet = [];
            foreach (array_keys($model->getChanges()) as $field) {
                $changeSet[$field] = [$model->getOriginal($field), $model->getAttribute($field)];
            }
            if ($changeSet === []) {
                return [];
            }
            $changes = $this->indexNow->changes();

            return [
                ...$changes->renamed($model, $changeSet, self::previousState($model), $this->selfFields($model)),
                ...$changes->updated($model, array_keys($changeSet), $changeSet),
            ];
        });
    }

    /** Before the row disappears: resolve now, deliver in deleted(). */
    public function deleting(Model $model): void
    {
        if (!$this->enabled) {
            return;
        }
        try {
            $this->pendingDeletions[$model] = ResolvedUrl::urls($this->indexNow->changes()->deleted($model));
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot resolve the URLs of {class} before deletion: {error}', ['class' => $model::class, 'error' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /** After a hard delete or a soft delete (the page answers 404 either way). */
    public function deleted(Model $model): void
    {
        if (!$this->enabled) {
            return;
        }
        $urls = $this->pendingDeletions[$model] ?? null;
        unset($this->pendingDeletions[$model]);
        if ($urls === null) {
            // deleting() was not seen (deleted without events on the way in); the model still carries its attributes.
            $this->guard($model, fn(): array => $this->indexNow->changes()->deleted($model));

            return;
        }
        $this->handOff($model, $urls);
    }

    /** SoftDeletes: the page is public again. */
    public function restored(Model $model): void
    {
        $this->guard($model, fn(): array => $this->indexNow->changes()->created($model));
    }

    /**
     * @param callable(): list<ResolvedUrl> $resolve
     */
    private function guard(Model $model, callable $resolve): void
    {
        if (!$this->enabled) {
            return;
        }
        try {
            $resolved = $resolve();
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot resolve the URLs of {class}: {error}', ['class' => $model::class, 'error' => $e->getMessage(), 'exception' => $e]);

            return;
        }
        foreach ($resolved as $item) {
            $this->logger->debug('indexnow: {source} ({event}) -> {url}', ['source' => $item->source(), 'event' => $item->event->value, 'url' => $item->url]);
        }
        $this->handOff($model, ResolvedUrl::urls($resolved));
    }

    /**
     * Inside a transaction the URLs wait for the real COMMIT; outside they go to the collector right away.
     *
     * @param list<string> $urls
     */
    private function handOff(Model $model, array $urls): void
    {
        if ($urls === []) {
            return;
        }
        try {
            $connection = $model->getConnection();
            if ($connection->transactionLevel() > 0) {
                try {
                    $connection->afterCommit(function () use ($urls): void {
                        $this->deliver($urls);
                    });

                    return;
                } catch (RuntimeException $e) {
                    $this->logger->warning('indexnow: connection "{connection}" has no transactions manager; submitting inside an open transaction: {error}', ['connection' => $connection->getName(), 'error' => $e->getMessage()]);
                }
            }
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot inspect the transaction state of {class}: {error}', ['class' => $model::class, 'error' => $e->getMessage(), 'exception' => $e]);
        }
        $this->deliver($urls);
    }

    /**
     * @param list<string> $urls
     */
    private function deliver(array $urls): void
    {
        try {
            $this->indexNow->collect($urls);
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot collect {count} URL(s): {error}', ['count' => \count($urls), 'error' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * A copy of the model as it was before the update (raw original attributes, relations unloaded so they reload
     * for the old foreign keys), used to resolve the URLs a renamed page had.
     */
    private static function previousState(Model $model): Model
    {
        $previous = clone $model;
        /** @var array<string, mixed> $original Laravel 11 declares getRawOriginal() as mixed for the no-key call */
        $original = $model->getRawOriginal();
        $previous->setRawAttributes($original, true);
        $previous->unsetRelations();

        return $previous;
    }

    /**
     * Fields a `params: ['post' => 'self']` route parameter depends on: the binding field of the route
     * (`{post:slug}`), else the model's route key.
     *
     * @return list<string>
     */
    private function selfFields(Model $model): array
    {
        $fields = [];
        foreach ($this->indexNow->changes()->rulesOf($model) as $rule) {
            if ($rule->source !== RuleSource::Route || $rule->route === null) {
                continue;
            }
            foreach ($rule->params as $name => $source) {
                if ($source === ParamExtractor::SELF) {
                    $fields[] = $this->router?->bindingFieldFor($rule->route, $name) ?? $model->getRouteKeyName();
                }
            }
        }

        return array_values(array_unique($fields));
    }
}
