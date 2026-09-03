<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use IndexNowKit\Http\Response;
use IndexNowKit\Laravel\Tests\Fixtures\Post;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class DisabledTest extends LaravelTestCase
{
    protected function configOverrides(): array
    {
        return ['enabled' => false];
    }

    #[TestDox('enabled: false -> observers are inert, the key file is still served, check warns')]
    public function testDisabled(): void
    {
        Post::query()->create(['slug' => 'off']);
        $this->kit()->flush();
        self::assertSame([], $this->transport->posts);
        self::assertSame(0, $this->kit()->collector->count());

        $this->get('/' . self::KEY . '.txt')->assertOk();
        $this->transport->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY));
        $this->transport->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY));
        $this->artisan('indexnow:check')
            ->expectsOutputToContain('IndexNow is disabled (enabled: false)')
            ->expectsOutputToContain('model observers are NOT active')
            ->assertExitCode(0);
    }
}
