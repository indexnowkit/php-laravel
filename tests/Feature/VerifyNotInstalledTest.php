<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Closure;
use Illuminate\Contracts\Console\Kernel;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use IndexNowKit\Laravel\IndexNowKitServiceProvider;
use IndexNowKit\Laravel\Tests\Fixtures\Post;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Laravel\Verify\VerifyServices;
use IndexNowKit\Submitter;
use IndexNowKit\SubmitterInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * indexnowkit/verify not installed (an OptionalPackage with installed: false under VERIFY_PACKAGE) while a `verify`
 * block is configured: the block is ignored as a whole, `check` says so, `--sample` is an error naming the install
 * line, nothing is fetched before a submission.
 */
final class VerifyNotInstalledTest extends LaravelTestCase
{
    /**
     * @return array<string, Closure>
     */
    protected function overrideApplicationBindings($app): array
    {
        return [IndexNowKitServiceProvider::VERIFY_PACKAGE => static fn(): OptionalPackage => VerifyServices::package(false)];
    }

    protected function configOverrides(): array
    {
        return ['verify' => ['enabled' => true, 'redirekt' => 'follow']];
    }

    #[TestDox('check prints the ignored-block line; --sample is an error with the install line')]
    public function testCheck(): void
    {
        $this->transport
            ->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY, headers: ['Content-Type' => 'text/plain']));

        [$code, $output] = $this->artisanCall('indexnow:check');
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('verify: not installed, the verify block in the configuration is ignored (composer require indexnowkit/verify) — pre-flight checks off', $output);

        [$code, $output] = $this->artisanCall('indexnow:check', ['--sample' => ['https://www.example.com/x']]);
        self::assertSame(ExitCode::FAILURE, $code);
        self::assertStringContainsString('check --sample needs indexnowkit/verify (composer require indexnowkit/verify)', $output);
        self::assertNotContains('https://www.example.com/x', $this->transport->gets);
        self::assertSame([], $this->logger->messages('warning'), 'the verify block (with a typo) is ignored as a whole');
    }

    public function testThePlainSubmitterAndNothingFetched(): void
    {
        Post::query()->create(['slug' => 'plain']);
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/plain'], $this->sentUrls());
        self::assertSame([], $this->transport->gets);
        self::assertInstanceOf(Submitter::class, $this->app->make(SubmitterInterface::class));
        self::assertSame($this->app->make(\IndexNowKit\Adapter\SubmitterFactoryInterface::class), $this->app->make(IndexNowKitServiceProvider::UNVERIFIED_SUBMITTER_FACTORY));

        [, $output] = $this->artisanCall('about', ['--only' => 'indexnow']);
        self::assertStringContainsString('not installed (composer require indexnowkit/verify)', $output);
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
