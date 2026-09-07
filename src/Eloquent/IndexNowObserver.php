<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Eloquent;

use Closure;
use Illuminate\Database\Eloquent\Model;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Attribute\RuleSource;
use IndexNowKit\Hook\ObserverHelper;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Url\ObjectChangeHandler;
use IndexNowKit\Url\ResolvedUrl;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Eloquent hooks. Synchronous on purpose (not ShouldHandleEventsAfterCommit): URLs are resolved while the old state
 * is still live — `getOriginal()` in `updated`, the row and its relations in `deleting` — and handed to the
 * collector through Connection::afterCommit(), which Laravel's DatabaseTransactionsManager runs only when the
 * outermost transaction commits and drops when the transaction (or the savepoint it belongs to) rolls back.
 *
 * The hooks resolve through the container's `Url\ObjectChangeHandler` alone (rules, resolver, extractor, logger):
 * a `save()` that produces no URL never builds the submitter, the client, the transport, the throttle or the
 * debounce store. The facade is made in the sink, once there are URLs to collect.
 *
 * Nothing here throws into the application: the core's ObjectChangeHandler logs and yields nothing on a bad rule,
 * every hand-off is guarded by `Hook\ObserverHelper`, and a change handler the container cannot build is one error
 * line per process. What is Laravel's: the change set from `getChanges()` / `getOriginal()`, the previous state
 * from `getRawOriginal()`, the commit boundary through `Connection::afterCommit()`.
 */
final class IndexNowObserver
{
    /** Model events the observer handles; {@see IndexNowable} registers exactly these. */
    public const EVENTS = ['created', 'updated', 'deleting', 'deleted', 'restored'];

    private ?ObserverHelper $helper = null;
    /** The change handler could not be built: logged once, every later hook is a no-op. */
    private bool $unavailable = false;

    /**
     * @param Closure(): ObjectChangeHandler $changes the container's change handler, resolved on the first hook
     * @param Closure(list<string>): void    $sink    where the resolved URLs go (the facade's `collect()`), built only when
     *                                                there are URLs: the graph behind it stays untouched otherwise
     */
    public function __construct(
        private readonly Closure $changes,
        private readonly Closure $sink,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $enabled = true,
        private readonly ?RouteBindingFieldsInterface $router = null,
    ) {}

    /** Over an already built facade (tests, an observer wired by hand): its change handler and its collector. */
    public static function forKit(IndexNowKit $indexNow, LoggerInterface $logger = new NullLogger(), bool $enabled = true, ?RouteBindingFieldsInterface $router = null): self
    {
        return new self(
            static fn(): ObjectChangeHandler => $indexNow->changes(),
            static function (array $urls) use ($indexNow): void {
                $indexNow->collect($urls);
            },
            $logger,
            $enabled,
            $router,
        );
    }

    public function created(Model $model): void
    {
        $this->guard($model, static fn(ObjectChangeHandler $changes): array => $changes->created($model));
    }

    public function updated(Model $model): void
    {
        $this->guard($model, function (ObjectChangeHandler $changes) use ($model): array {
            $changeSet = [];
            foreach (array_keys($model->getChanges()) as $field) {
                $changeSet[$field] = [$model->getOriginal($field), $model->getAttribute($field)];
            }
            if ($changeSet === []) {
                return [];
            }

            return [
                ...$changes->renamed($model, $changeSet, self::previousState($model), $this->selfFields($changes, $model)),
                ...$changes->updated($model, array_keys($changeSet), $changeSet),
            ];
        });
    }

    /** Before the row disappears: resolve now, deliver in deleted(). */
    public function deleting(Model $model): void
    {
        $helper = $this->helper();
        if ($helper === null) {
            return;
        }
        $urls = $helper->guard($model, static fn(ObjectChangeHandler $changes): array => $changes->deleted($model));
        if ($urls !== null) {
            $helper->rememberDeletion($model, $urls);
        }
    }

    /** After a hard delete or a soft delete (the page answers 404 either way). */
    public function deleted(Model $model): void
    {
        $helper = $this->helper();
        if ($helper === null) {
            return;
        }
        $urls = $helper->takeDeletion($model);
        if ($urls === null) {
            // deleting() was not seen (deleted without events on the way in); the model still carries its attributes.
            $this->guard($model, static fn(ObjectChangeHandler $changes): array => $changes->deleted($model));

            return;
        }
        $this->handOff($model, $urls);
    }

    /** SoftDeletes: the page is public again. */
    public function restored(Model $model): void
    {
        $this->guard($model, static fn(ObjectChangeHandler $changes): array => $changes->created($model));
    }

    /**
     * @param callable(ObjectChangeHandler): list<ResolvedUrl> $resolve
     */
    private function guard(Model $model, callable $resolve): void
    {
        $helper = $this->helper();
        if ($helper === null) {
            return;
        }
        $urls = $helper->guard($model, $resolve);
        if ($urls !== null) {
            $this->handOff($model, $urls);
        }
    }

    /**
     * The helper over the container's change handler, built on the first hook that needs it; null when the hooks are
     * off or the change handler cannot be built (one error line, never an exception in `save()`).
     */
    private function helper(): ?ObserverHelper
    {
        if (!$this->enabled || $this->unavailable) {
            return null;
        }
        if ($this->helper !== null) {
            return $this->helper;
        }
        try {
            return $this->helper = ObserverHelper::forChanges(($this->changes)(), $this->sink, $this->logger);
        } catch (Throwable $e) {
            $this->unavailable = true;
            $this->logger->error('indexnow: cannot build the change handler; model changes are not announced: {error}', ['error' => $e->getMessage(), 'exception' => $e]);

            return null;
        }
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
        $this->helper()?->deliver($urls);
    }

    /**
     * A copy of the model as it was before the update (raw original attributes, relations unloaded so they reload
     * for the old foreign keys), used to resolve the URLs a renamed page had.
     */
    private static function previousState(Model $model): Model
    {
        $previous = clone $model;
        /** @var array<string, mixed> $original getRawOriginal() is declared mixed for the no-key call in older Laravel versions */
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
    private function selfFields(ObjectChangeHandler $changes, Model $model): array
    {
        $fields = [];
        foreach ($changes->rulesOf($model) as $rule) {
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
