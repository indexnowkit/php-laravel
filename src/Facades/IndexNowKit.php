<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use IndexNowKit\Laravel\IndexNowManager;

/**
 * @method static \IndexNowKit\IndexNowKit             kit()
 * @method static \IndexNowKit\Attribute\RuleRegistry  rules()
 * @method static void                                 observe(class-string<\Illuminate\Database\Eloquent\Model> $class, list<\IndexNowKit\Attribute\IndexNow> $rules = [], ?\IndexNowKit\Attribute\IndexNowDefaults $defaults = null)
 * @method static list<\IndexNowKit\Result>            submit(iterable<string> $urls)
 * @method static list<\IndexNowKit\Result>            submitModel(object $model, \IndexNowKit\Event $event = \IndexNowKit\Event::Updated)
 * @method static list<\IndexNowKit\Result>            submitModels(iterable<object> $models, \IndexNowKit\Event $event = \IndexNowKit\Event::Updated)
 * @method static list<string>                         urlsFor(object $model, \IndexNowKit\Event $event = \IndexNowKit\Event::Updated)
 * @method static list<string>                         urlsForAll(iterable<object> $models, \IndexNowKit\Event $event = \IndexNowKit\Event::Updated)
 * @method static list<\IndexNowKit\Url\ResolvedUrl>   explain(object $model, \IndexNowKit\Event $event = \IndexNowKit\Event::Updated)
 * @method static void                                 collect(iterable<string> $urls)
 * @method static void                                 flush()
 *
 * @see IndexNowManager
 */
final class IndexNowKit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return IndexNowManager::class;
    }
}
