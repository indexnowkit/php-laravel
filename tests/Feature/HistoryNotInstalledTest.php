<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Closure;
use Illuminate\Contracts\Console\Kernel;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use IndexNowKit\Laravel\History\HistoryServices;
use IndexNowKit\Laravel\IndexNowKitServiceProvider;
use IndexNowKit\Laravel\Tests\Fixtures\Post;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Submission\NullSubmissionStore;
use IndexNowKit\Submission\SubmissionStoreInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * indexnowkit/history not installed (an OptionalPackage with installed: false under HISTORY_PACKAGE) while a
 * `history` block is configured: the block is ignored as a whole, `check` says so, `indexnow:history` and
 * `indexnow:status` print the install line and exit 1, nothing is recorded.
 */
final class HistoryNotInstalledTest extends LaravelTestCase
{
    /**
     * @return array<string, Closure>
     */
    protected function overrideApplicationBindings($app): array
    {
        return [IndexNowKitServiceProvider::HISTORY_PACKAGE => static fn(): OptionalPackage => HistoryServices::package(false)];
    }

    protected function configOverrides(): array
    {
        return ['history' => ['store' => 'pdo', 'pdo' => ['tabel' => 'x']]];
    }

    #[TestDox('check prints the ignored-block line; the commands print the install line and exit 1')]
    public function testCheckAndTheStubs(): void
    {
        $this->transport
            ->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY, headers: ['Content-Type' => 'text/plain']));

        [$code, $output] = $this->artisanCall('indexnow:check');
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('history: not installed, the history block in the configuration is ignored (composer require indexnowkit/history)', $output);
        self::assertSame([], $this->logger->messages('warning'), 'the history block (with a typo) is ignored as a whole');
        [$code] = $this->artisanCall('indexnow:check', ['--strict' => true]);
        self::assertSame(ExitCode::FAILURE, $code, 'an ignored block is a warning');

        foreach (['indexnow:history', 'indexnow:status'] as $command) {
            [$code, $output] = $this->artisanCall($command, ['--json' => true]);
            self::assertSame(ExitCode::FAILURE, $code);
            self::assertSame('indexnowkit/history is not installed: composer require indexnowkit/history', trim($output));
        }
    }

    public function testNothingIsRecorded(): void
    {
        Post::query()->create(['slug' => 'plain']);
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/plain'], $this->sentUrls());
        self::assertInstanceOf(NullSubmissionStore::class, $this->app->make(SubmissionStoreInterface::class));

        [, $output] = $this->artisanCall('about', ['--only' => 'indexnow']);
        self::assertStringContainsString('not installed (composer require indexnowkit/history)', $output);

        [, $output] = $this->artisanCall('indexnow:config', ['--json' => true]);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('history', $decoded);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function artisanCall(string $command, array $arguments = []): array
    {
        $output = new BufferedOutput();
        $code = $this->app->make(Kernel::class)->call($command, $arguments, $output);

        return [$code, $output->fetch()];
    }
}
