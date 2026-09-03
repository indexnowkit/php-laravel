<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel;

use Illuminate\Database\Eloquent\Model;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\IndexNowDefaults;
use IndexNowKit\Attribute\RuleRegistry;
use IndexNowKit\Event;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Laravel\Eloquent\IndexNowObserver;
use IndexNowKit\Result;
use IndexNowKit\Url\ResolvedUrl;

/**
 * Root of the `IndexNowKit` facade: the core facade plus what is Laravel-specific — registering models without a
 * trait or attribute, and submitting Eloquent models in bulk (the manual path for mass updates, conformance A13).
 */
final class IndexNowManager
{
    public function __construct(private readonly IndexNowKit $kit, private readonly RuleRegistry $rules) {}

    /**
     * The core facade (Config, Submitter, Collector, `changes()`, `resolver()`).
     */
    public function kit(): IndexNowKit
    {
        return $this->kit;
    }

    /**
     * Rules registered at runtime, on top of the attributes: `rules()->registerFor(Page::class, fn(Page $p): ?RuleSet => ...)`.
     */
    public function rules(): RuleRegistry
    {
        return $this->rules;
    }

    /**
     * Hooks a model class that has no IndexNowable trait: with $rules, they replace whatever #[IndexNow] attributes
     * the class carries; without, the class's own attributes are used.
     *
     *   IndexNowKit::observe(Post::class, [new IndexNow(route: 'posts.show', params: ['post' => 'self'])], new IndexNowDefaults(when: 'isPublished'));
     *
     * @param class-string<Model> $class
     * @param list<IndexNow>      $rules
     *
     * @throws \IndexNowKit\Exception\ConfigurationException on an invalid rule
     */
    public function observe(string $class, array $rules = [], ?IndexNowDefaults $defaults = null): void
    {
        if ($rules !== [] || $defaults !== null) {
            $this->rules->register($class, $rules, $defaults);
        }
        $class::observe(IndexNowObserver::class);
    }

    /**
     * Submit URLs immediately (bypasses collector and queue).
     *
     * @param iterable<string> $urls
     *
     * @return list<Result>
     */
    public function submit(iterable $urls): array
    {
        return $this->kit->submit($urls);
    }

    /**
     * Resolve one model's URLs through its rules and submit them immediately.
     *
     * @return list<Result>
     */
    public function submitModel(object $model, Event $event = Event::Updated): array
    {
        return $this->kit->submitEntity($model, $event);
    }

    /**
     * Resolve the URLs of many models and submit them in one call (one request per host and batch). The manual
     * path after `Post::where(...)->update()` and other bulk operations, which fire no model events.
     *
     * @param iterable<object> $models
     *
     * @return list<Result>
     */
    public function submitModels(iterable $models, Event $event = Event::Updated): array
    {
        return $this->kit->submit($this->urlsForAll($models, $event));
    }

    /**
     * URLs the rules yield for many models, de-duplicated.
     *
     * @param iterable<object> $models
     *
     * @return list<string>
     */
    public function urlsForAll(iterable $models, Event $event = Event::Updated): array
    {
        $resolved = [];
        foreach ($models as $model) {
            $resolved = [...$resolved, ...$this->kit->explain($model, $event)];
        }

        return ResolvedUrl::urls($resolved);
    }

    /**
     * @return list<string>
     */
    public function urlsFor(object $model, Event $event = Event::Updated): array
    {
        return $this->kit->urlsFor($model, $event);
    }

    /**
     * @return list<ResolvedUrl>
     */
    public function explain(object $model, Event $event = Event::Updated): array
    {
        return $this->kit->explain($model, $event);
    }

    /**
     * Park URLs in the request collector; they leave with flush() (app()->terminating()).
     *
     * @param iterable<string> $urls
     */
    public function collect(iterable $urls): void
    {
        $this->kit->collect($urls);
    }

    public function flush(): void
    {
        $this->kit->flush();
    }
}
