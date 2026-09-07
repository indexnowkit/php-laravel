<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use IndexNowKit\ClientInterface;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Laravel\Tests\Fixtures\Post;
use IndexNowKit\Laravel\Tests\Fixtures\Untracked;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Url\ObjectChangeHandler;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * What a model event costs: the hooks go through `Url\ObjectChangeHandler` alone, and the delivery side of the graph
 * (facade, submitter, client, throttle, debounce store) is built only once a save actually produced a URL.
 */
final class ObserverGraphTest extends LaravelTestCase
{
    #[TestDox('a save that resolves no URL builds the change handler and nothing else')]
    public function testSaveWithoutUrlsDoesNotBuildTheDeliveryGraph(): void
    {
        Untracked::query()->create(['name' => 'nothing to announce']);

        self::assertTrue($this->app->resolved(ObjectChangeHandler::class), 'the hook asked the change handler');
        foreach ([IndexNowKit::class, SubmitterInterface::class, ClientInterface::class, ThrottleInterface::class, DebounceStoreInterface::class] as $abstract) {
            self::assertFalse($this->app->resolved($abstract), $abstract . ' must not be built by a save that announces nothing');
        }
    }

    #[TestDox('a save that resolves a URL builds the facade in the sink and collects through it')]
    public function testSaveWithUrlsBuildsTheFacadeOnce(): void
    {
        Post::query()->create(['slug' => 'announced']);

        self::assertTrue($this->app->resolved(IndexNowKit::class), 'the sink made the facade');
        self::assertSame(1, $this->kit()->collector->count());
    }

    #[TestDox('a change handler the container cannot build is one error line, not an exception in save()')]
    public function testBrokenChangeHandlerDoesNotBreakSave(): void
    {
        $this->app->bind(ObjectChangeHandler::class, static function (): never {
            throw new ConfigurationException('the rule reader is misconfigured');
        });

        $first = Post::query()->create(['slug' => 'saved-anyway']);
        $second = Post::query()->create(['slug' => 'saved-too']);

        self::assertTrue($first->exists);
        self::assertTrue($second->exists);
        self::assertSame([], $this->transport->posts);
        $errors = array_values(array_filter($this->logger->messages('error'), static fn(string $m): bool => str_contains($m, 'cannot build the change handler')));
        self::assertCount(1, $errors, 'logged once per process, not once per save');
        self::assertStringContainsString('the rule reader is misconfigured', $errors[0]);
    }
}
