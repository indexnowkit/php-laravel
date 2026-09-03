<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Conformance;

use Illuminate\Foundation\Application;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Laravel\Tests\Support\Harness;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\Conformance\CoreConformanceTestCase;
use IndexNowKit\Testing\FakeTransport;

/**
 * The core conformance scenarios (C01-C20) against the facade the service provider wires; `hosts` has a second
 * host, so C04 runs too.
 */
final class CoreConformanceTest extends CoreConformanceTestCase
{
    private Application $app;
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->app = Harness::create($this->transport, new ArrayLogger());
    }

    protected function tearDown(): void
    {
        Harness::destroy($this->app);
    }

    protected function kit(): IndexNowKit
    {
        return $this->app->make(IndexNowKit::class);
    }

    protected function transport(): FakeTransport
    {
        return $this->transport;
    }

    protected function secondHost(): ?string
    {
        return 'example.de';
    }
}
