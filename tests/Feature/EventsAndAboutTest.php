<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Events\Dispatcher;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Laravel\IndexNowKitServiceProvider;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Result;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\EventDispatcher\EventDispatcherInterface;

final class EventsAndAboutTest extends LaravelTestCase
{
    #[TestDox('every Result goes through Laravel\'s event dispatcher: Event::listen(Result::class) receives it (Telescope sees it), from the submitter and from the command submitters alike')]
    public function testResultsAreLaravelEvents(): void
    {
        $seen = [];
        $this->app->make(Dispatcher::class)->listen(Result::class, static function (Result $result) use (&$seen): void {
            $seen[] = $result->engine . ' ' . $result->status->value;
        });
        self::assertInstanceOf(EventDispatcherInterface::class, $this->app->make(IndexNowKitServiceProvider::EVENTS));

        $this->kit()->submit(['/a']);
        self::assertSame(['api ok'], $seen);

        $this->artisan('indexnow:submit', ['urls' => ['/b'], '--dry-run' => true])->assertExitCode(0);
        self::assertSame(['api ok', 'api skipped'], $seen, 'the command submitter publishes too');
    }

    #[TestDox('php artisan about has an IndexNow section with the key masked')]
    public function testAboutSection(): void
    {
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        self::assertSame(0, $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->call('about', ['--only' => 'indexnow'], $output));
        $output = $output->fetch();

        self::assertStringContainsString('IndexNow', $output);
        self::assertStringContainsString(KeyValidator::mask(self::KEY), $output);
        self::assertStringNotContainsString(self::KEY, $output);
        self::assertStringContainsString('indexnow:check --strict', $output);
        self::assertStringContainsString('https://www.example.com', $output);
    }
}
