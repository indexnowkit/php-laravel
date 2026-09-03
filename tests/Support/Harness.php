<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Laravel\IndexNowKitServiceProvider;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use Orchestra\Testbench\Foundation\Application;

/**
 * A booted Testbench application with the package, for test cases that extend the core conformance kits instead of
 * the Testbench TestCase.
 */
final class Harness
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $configOverrides
     */
    public static function create(FakeTransport $transport, ArrayLogger $logger, array $configOverrides = []): LaravelApplication
    {
        $app = Application::create(options: ['extra' => ['providers' => [IndexNowKitServiceProvider::class], 'dont-discover' => ['*']], 'load_environment_variables' => false]);
        $config = $app->make(Repository::class);
        $current = $config->get('indexnow');
        $config->set('indexnow', Fixtures::merge(Fixtures::merge(\is_array($current) ? $current : [], Fixtures::config()), $configOverrides));
        $config->set('app.url', 'http://localhost');
        $app->instance(TransportInterface::class, $transport);
        $app->instance(IndexNowKitServiceProvider::LOGGER, $logger);
        Fixtures::routes($app->make(Router::class));
        Fixtures::migrate($app);

        return $app;
    }

    /** Undo what booting did to the process: error/exception handlers, facade roots, the container. */
    public static function destroy(LaravelApplication $app): void
    {
        $app->flush();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        HandleExceptions::flushHandlersState();
        Carbon::setTestNow();
    }
}
