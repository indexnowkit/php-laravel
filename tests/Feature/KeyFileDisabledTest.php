<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use IndexNowKit\Laravel\Tests\LaravelTestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class KeyFileDisabledTest extends LaravelTestCase
{
    protected function configOverrides(): array
    {
        return ['key_file' => ['enabled' => false]];
    }

    #[TestDox('H03 key_file.enabled false -> 404 on /{key}.txt, the route is not registered')]
    public function testH03Disabled(): void
    {
        $this->get('/' . self::KEY . '.txt')->assertNotFound();
        self::assertNull($this->app->make('router')->getRoutes()->getByName('indexnow.key_file'));
    }
}
