<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Router;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Laravel\IndexNowKitServiceProvider;
use IndexNowKit\Laravel\Tests\Support\Fixtures;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use Orchestra\Testbench\TestCase;

/**
 * Testbench application with the package, sqlite in memory, the conformance fixtures and their routes, a
 * FakeTransport instead of HTTP and an ArrayLogger instead of the log channel.
 */
abstract class LaravelTestCase extends TestCase
{
    public const KEY = Fixtures::KEY;
    public const SECOND_KEY = Fixtures::SECOND_KEY;
    public const BASE_URL = Fixtures::BASE_URL;

    protected FakeTransport $transport;
    protected ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->logger = new ArrayLogger();
        // After the provider registered its bindings: bind() would drop an instance set earlier.
        $this->afterApplicationCreated(function (): void {
            $this->app->instance(TransportInterface::class, $this->transport);
            $this->app->instance(IndexNowKitServiceProvider::LOGGER, $this->logger);
        });
        parent::setUp();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [IndexNowKitServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);
        $config->set('app.url', 'http://localhost');
        $config->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $config->set('indexnow', Fixtures::merge(Fixtures::config(), $this->configOverrides()));
    }

    /**
     * @return array<string, mixed>
     */
    protected function configOverrides(): array
    {
        return [];
    }

    protected function defineRoutes($router): void
    {
        \assert($router instanceof Router);
        Fixtures::routes($router);
    }

    protected function defineDatabaseMigrations(): void
    {
        Fixtures::migrate($this->app);
    }

    protected function kit(): IndexNowKit
    {
        return $this->app->make(IndexNowKit::class);
    }

    /**
     * @return list<string>
     */
    protected function sentUrls(): array
    {
        $urls = [];
        foreach ($this->transport->posts as $post) {
            /** @var list<string> $list */
            $list = $post['body']['urlList'];
            $urls = [...$urls, ...$list];
        }

        return $urls;
    }
}
