<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Event;

use Illuminate\Contracts\Events\Dispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Laravel's event dispatcher as PSR-14, so every `Result` the submitter produces goes through `Event::dispatch()`:
 * `Event::listen(Result::class, ...)` receives it, Telescope's events watcher shows it, `Event::fake()` catches it.
 * Bound under `IndexNowKitServiceProvider::EVENTS`.
 */
final class EventDispatcherBridge implements EventDispatcherInterface
{
    public function __construct(private readonly Dispatcher $events) {}

    public function dispatch(object $event): object
    {
        $this->events->dispatch($event);

        return $event;
    }
}
