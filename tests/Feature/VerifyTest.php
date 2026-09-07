<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use IndexNowKit\Laravel\IndexNowKitServiceProvider;
use IndexNowKit\Laravel\Tests\Fixtures\Post;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Result;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Verify\VerifyingSubmitter;
use IndexNowKit\Verify\VerifyingSubmitterFactory;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * indexnowkit/verify installed and `verify.enabled: true` with `dispatch: sync`: the `SubmitterInterface` binding
 * is the decorator, a noindex page is skipped at flush, the Result event sees the skipped result, `check` prints
 * the verify lines and `--sample` reports, `config --json` has the `verify` section, `about` names it.
 */
final class VerifyTest extends LaravelTestCase
{
    protected function configOverrides(): array
    {
        return ['verify' => ['enabled' => true, 'redirect' => 'follow', 'user_agent' => 'test-verify/1']];
    }

    #[TestDox('the submitter is the decorator: a noindex post is skipped at flush, the Result event carries it once, the plain factory stays bound apart')]
    public function testNoindexPostIsSkipped(): void
    {
        $seen = [];
        $this->app->make(Dispatcher::class)->listen(Result::class, static function (Result $result) use (&$seen): void {
            $reason = $result->reason;
            $seen[] = $result->status->value . ':' . ($reason === null ? '-' : $reason->value);
        });
        $this->transport
            ->onGet('https://www.example.com/posts/fine', new Response(200))
            ->onGet('https://www.example.com/posts/hidden', new Response(200, '<head><meta name="robots" content="noindex"></head>'));

        Post::query()->create(['slug' => 'fine']);
        Post::query()->create(['slug' => 'hidden']);
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/fine'], $this->sentUrls());
        self::assertContains('https://www.example.com/robots.txt', $this->transport->gets);
        sort($seen);
        self::assertSame(['ok:-', 'skipped:noindex'], $seen);
        self::assertInstanceOf(VerifyingSubmitter::class, $this->app->make(SubmitterInterface::class));
        self::assertInstanceOf(VerifyingSubmitterFactory::class, $this->app->make(SubmitterFactoryInterface::class));
        self::assertNotInstanceOf(VerifyingSubmitterFactory::class, $this->app->make(IndexNowKitServiceProvider::UNVERIFIED_SUBMITTER_FACTORY));
        self::assertContains('indexnow verify: skipped https://www.example.com/posts/hidden: noindex (meta robots)', $this->logger->messages('info'));
    }

    public function testCheckPrintsTheVerifyLinesAndTheSamples(): void
    {
        $this->transport
            ->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://www.example.com/posts/fine', new Response(200))
            ->onGet('https://www.example.com/posts/hidden', new Response(200, '<head><meta name="robots" content="noindex"></head>'));

        [$code, $output] = $this->artisanCall('indexnow:check');
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('verify: enabled (redirect: follow, non_canonical: skip, origin_error: skip)', $output);
        self::assertStringContainsString('verify.enabled with dispatch: sync fetches your own pages inside the web request; use dispatch: queue', $output);
        self::assertStringContainsString('verify sample: no sample given', $output);

        [$code, $output] = $this->artisanCall('indexnow:check', ['--sample' => ['/posts/fine', '/posts/hidden'], '--json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code, 'a noindex sample is a warning, not a failure');
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $samples = array_values(array_filter($decoded['items'], static fn(array $i): bool => $i['code'] === 'verify.sample'));
        self::assertSame(['ok', 'verify sample https://www.example.com/posts/fine: HTTP 200, index, canonical: self, robots: allowed'], [$samples[0]['level'], $samples[0]['message']]);
        self::assertSame('warning', $samples[1]['level']);
        self::assertStringContainsString('noindex (meta robots)', $samples[1]['message']);

        Post::query()->create(['slug' => 'fine']);
        [$code, $output] = $this->artisanCall('indexnow:check', ['--sample-class' => [Post::class], '--json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertContains('verify sample https://www.example.com/posts/fine: HTTP 200, index, canonical: self, robots: allowed', array_column(array_filter($decoded['items'], static fn(array $i): bool => $i['code'] === 'verify.sample'), 'message'));
    }

    #[TestDox('indexnow:sitemap verifies through the decorated command factory; the no-verify flag takes the plain one')]
    public function testSitemapNoVerify(): void
    {
        $this->transport
            ->onGet('https://www.example.com/sitemap.xml', new Response(200, '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.example.com/s1</loc></url><url><loc>https://www.example.com/s2</loc></url></urlset>'))
            ->onGet('https://www.example.com/s1', new Response(200))
            ->onGet('https://www.example.com/s2', new Response(200, '<head><meta name="robots" content="noindex"></head>'));

        [$code] = $this->artisanCall('indexnow:sitemap', ['--force' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        self::assertSame(['https://www.example.com/s1'], $this->sentUrls(), 'the command factory is decorated');

        [$code] = $this->artisanCall('indexnow:sitemap', ['--force' => true, '--no-verify' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        self::assertSame(['https://www.example.com/s1', 'https://www.example.com/s1', 'https://www.example.com/s2'], $this->sentUrls(), 'the plain factory submits everything');
    }

    public function testConfigJsonAndAboutNameTheVerifySection(): void
    {
        [$code, $output] = $this->artisanCall('indexnow:config', ['--json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(['enabled' => true, 'redirect' => 'follow', 'non_canonical' => 'skip', 'origin_error' => 'skip', 'delay' => 0, 'timeout' => 5, 'max_redirects' => 3, 'max_batch' => 100, 'time_budget' => 60, 'robots_cache_ttl' => 3600, 'user_agent' => 'test-verify/1'], $decoded['verify']);
        self::assertArrayNotHasKey('verify', $decoded['adapter']);

        [, $output] = $this->artisanCall('about', ['--only' => 'indexnow']);
        self::assertStringContainsString('enabled (redirect: follow, non_canonical: skip, origin_error: skip)', $output);
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
