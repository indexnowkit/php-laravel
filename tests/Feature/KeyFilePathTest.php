<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Testing\Conformance\KeyFileAssertions;
use PHPUnit\Framework\Attributes\TestDox;

final class KeyFilePathTest extends LaravelTestCase
{
    protected function configOverrides(): array
    {
        return ['hosts' => [], 'key_file' => ['path' => '/.well-known/{key}.txt', 'route_name' => 'seo.key', 'cache_max_age' => 60, 'middleware' => ['web']]];
    }

    #[TestDox('key_file.path, route_name, cache_max_age and middleware are honoured; no Vary without a hosts map')]
    public function testCustomPath(): void
    {
        $route = $this->app->make('router')->getRoutes()->getByName('seo.key');
        self::assertNotNull($route);
        self::assertSame(['web'], $route->middleware());

        $response = $this->get('/.well-known/' . self::KEY . '.txt');
        KeyFileAssertions::assertKeyFileResponse($response->getStatusCode(), $response->headers->all(), (string) $response->getContent(), self::KEY, 60, expectVaryHost: false);
        KeyFileAssertions::assertNotServed($this->get('/' . self::KEY . '.txt')->getStatusCode());
    }
}
