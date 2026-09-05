<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use IndexNowKit\Http\Response;
use IndexNowKit\Laravel\Tests\Fixtures\Post;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Testing\Conformance\CheckOutputAssertions;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

final class CommandsTest extends LaravelTestCase
{
    #[TestDox('H04 indexnow:check with reachable key files -> exit 0, host, engine and wiring lines')]
    public function testCheckOk(): void
    {
        $this->stubKeyFiles();

        [$code, $output] = $this->artisanCall('indexnow:check');

        CheckOutputAssertions::assertExitCode(0, $code, $output);
        CheckOutputAssertions::assertReady($output, 'www.example.com', 'example.de');
        foreach (['engines: api', 'dispatch "sync"', 'debounce: off', 'spooled in memory', 'eloquent: models using IndexNowable'] as $expected) {
            self::assertStringContainsString($expected, $output);
        }
    }

    #[TestDox('H05 indexnow:check when the key file answers 403 -> exit 1 with the hint')]
    public function testCheckForbidden(): void
    {
        $this->transport->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(403));
        $this->transport->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY));

        [$code, $output] = $this->artisanCall('indexnow:check');

        CheckOutputAssertions::assertExitCode(1, $code, $output);
        CheckOutputAssertions::assertKeyFileHint($output, 403);
    }

    #[TestDox('indexnow:config --json prints the effective configuration with masked keys and the Laravel-only keys')]
    public function testConfig(): void
    {
        [$code, $output] = $this->artisanCall('indexnow:config', ['--json' => true]);

        self::assertSame(0, $code, $output);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertStringNotContainsString(self::KEY, $output);
        self::assertSame(\IndexNowKit\Key\KeyValidator::mask(self::KEY), $decoded['config']['key']);
        self::assertSame('https://www.example.com', $decoded['config']['base_url']);
        self::assertSame(['locales' => ['en', 'de']], $decoded['adapter']['router'], 'the Laravel blocks are reported as given');
        self::assertArrayHasKey('sitemap', $decoded['adapter']);
        self::assertArrayNotHasKey('key', $decoded['adapter']);
        self::assertArrayNotHasKey('debounce', $decoded['adapter'], 'core blocks are in config, not adapter');

        [$code, $output] = $this->artisanCall('indexnow:config');
        self::assertSame(0, $code);
        self::assertStringContainsString('debounce.per_url', $output);
        self::assertStringNotContainsString(self::KEY, $output);
    }

    #[TestDox('indexnow:check --host limits the key file check to one host')]
    public function testCheckOnlyHost(): void
    {
        $this->transport->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY));

        [$code] = $this->artisanCall('indexnow:check', ['--host' => 'example.de']);

        self::assertSame(0, $code);
        self::assertSame(['https://example.de/' . self::SECOND_KEY . '.txt', 'https://example.de/robots.txt'], $this->transport->gets, 'the key file and robots.txt of that host only');
    }

    #[TestDox('indexnow:key:generate prints a 32-char hex key; --alphanumeric --length change alphabet and length')]
    public function testKeyGenerate(): void
    {
        [$code, $output] = $this->artisanCall('indexnow:key:generate');
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/INDEXNOW_KEY=[0-9a-f]{32}/', $output);

        [$code, $output] = $this->artisanCall('indexnow:key:generate', ['--length' => '16', '--alphanumeric' => true]);
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/INDEXNOW_KEY=[A-Za-z0-9]{16}\n/', $output);
    }

    #[TestDox('indexnow:key:generate --write-env writes INDEXNOW_KEY once and rotates only with --force')]
    public function testKeyGenerateWriteEnv(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        self::assertIsString($file);
        file_put_contents($file, "APP_NAME=x\n");
        try {
            [$code] = $this->artisanCall('indexnow:key:generate', ['--write-env' => $file]);
            self::assertSame(0, $code);
            $first = (string) file_get_contents($file);
            self::assertMatchesRegularExpression('/^APP_NAME=x\nINDEXNOW_KEY=[0-9a-f]{32}\n$/', $first);

            [$code, $output] = $this->artisanCall('indexnow:key:generate', ['--write-env' => $file]);
            self::assertSame(0, $code);
            self::assertStringContainsString('nothing to do', $output);
            self::assertSame($first, file_get_contents($file));

            [$code, $output] = $this->artisanCall('indexnow:key:generate', ['--write-env' => $file, '--force' => true]);
            self::assertSame(0, $code);
            self::assertStringContainsString('Rotating the key', $output);
            self::assertNotSame($first, file_get_contents($file));
            self::assertSame(1, preg_match_all('/^INDEXNOW_KEY=/m', (string) file_get_contents($file)));
        } finally {
            @unlink($file);
        }
    }

    #[TestDox('indexnow:submit sends the URLs and prints a table; --json prints results')]
    public function testSubmit(): void
    {
        [$code, $output] = $this->artisanCall('indexnow:submit', ['urls' => ['/a', 'https://www.example.com/b']]);
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/\\bapi\\s+www\\.example\\.com\\s+2\\s+ok\\b/', $output);
        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $this->sentUrls());

        [$code, $output] = $this->artisanCall('indexnow:submit', ['urls' => ['/c'], '--json' => true]);
        self::assertSame(0, $code);
        self::assertStringContainsString('"status": "ok"', $output);
        self::assertStringContainsString('"https://www.example.com/c"', $output);
    }

    #[TestDox('indexnow:submit --dry-run sends nothing and reports the reason; a failed engine answer gives exit 1')]
    public function testSubmitDryRunAndFailure(): void
    {
        [$code, $output] = $this->artisanCall('indexnow:submit', ['urls' => ['/a'], '--dry-run' => true]);
        self::assertSame(0, $code);
        self::assertStringContainsString('dry_run', $output);
        self::assertStringContainsString('Nothing was sent', $output);
        self::assertSame([], $this->transport->posts);

        $this->transport->willRespond(new Response(403));
        [$code] = $this->artisanCall('indexnow:submit', ['urls' => ['/a']]);
        self::assertSame(1, $code);
    }

    #[TestDox('indexnow:submit-model resolves models through their rules; --explain lists rule and URL; unknown class and id are reported')]
    public function testSubmitModel(): void
    {
        Post::query()->create(['slug' => 'one']);
        Post::query()->create(['slug' => 'two']);
        Post::query()->create(['slug' => 'draft', 'published' => false]);
        $this->kit()->flush();
        $this->transport->posts = [];

        [$code, $output] = $this->artisanCall('indexnow:submit-model', ['model' => Post::class]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('3 models -> 2 URL(s)', $output);
        self::assertEqualsCanonicalizing(['https://www.example.com/posts/one', 'https://www.example.com/posts/two'], $this->sentUrls());

        [$code, $output] = $this->artisanCall('indexnow:submit-model', ['model' => Post::class, 'ids' => ['1'], '--explain' => true]);
        self::assertSame(0, $code);
        self::assertStringContainsString('posts.show', $output);
        self::assertStringContainsString('https://www.example.com/posts/one', $output);
        self::assertCount(1, $this->transport->posts, '--explain sends nothing');

        [$code, $output] = $this->artisanCall('indexnow:submit-model', ['model' => Post::class, 'ids' => ['999']]);
        self::assertSame(2, $code);
        self::assertStringContainsString('not found', $output);
        [$code, $output] = $this->artisanCall('indexnow:submit-model', ['model' => 'Nope']);
        self::assertSame(2, $code);
        self::assertStringContainsString('not found', $output);
        [$code, $output] = $this->artisanCall('indexnow:submit-model', ['model' => Post::class, '--event' => 'moved']);
        self::assertSame(2, $code);
        self::assertStringContainsString('--event must be', $output);
        [$code, $output] = $this->artisanCall('indexnow:submit-model', ['model' => Post::class, 'ids' => ['1'], '--json' => true, '--dry-run' => true]);
        self::assertSame(0, $code);
        self::assertStringContainsString('"reason": "dry_run"', $output);
    }

    #[TestDox('indexnow:explain walks rule, when, URL, key and debounce for one model and sends nothing')]
    public function testExplain(): void
    {
        $post = Post::query()->create(['slug' => 'why', 'published' => false]);
        $this->kit()->flush();

        [$code, $output] = $this->artisanCall('indexnow:explain', ['model' => Post::class, 'id' => (string) $post->id]);
        self::assertSame(0, $code);
        self::assertStringContainsString('Rule "posts.show" (route posts.show)', $output);
        self::assertMatchesRegularExpression('/when: published \((false|0)\) -> false/', $output, 'the value the condition read is shown');
        self::assertStringContainsString('No URL would be submitted', $output);

        $post->update(['published' => true]);
        $this->kit()->flush();
        [$code, $output] = $this->artisanCall('indexnow:explain', ['model' => Post::class, 'id' => (string) $post->id]);
        self::assertSame(0, $code);
        self::assertStringContainsString('url: https://www.example.com/posts/why', $output);
        self::assertStringContainsString('host www.example.com, key abcd', $output);
        self::assertStringContainsString('Nothing was sent', $output);
        self::assertCount(1, $this->transport->posts);

        [$code] = $this->artisanCall('indexnow:explain', ['model' => Post::class, 'id' => '999']);
        self::assertSame(2, $code);
        [$code] = $this->artisanCall('indexnow:explain', ['model' => 'Nope', 'id' => '1']);
        self::assertSame(2, $code);
        [$code] = $this->artisanCall('indexnow:explain', ['model' => Post::class, 'id' => '1', '--event' => 'nope']);
        self::assertSame(2, $code);
    }

    #[TestDox('indexnow:sitemap reads a local sitemap, submits in batches and prints a summary; --dry-run lists; --json is machine-readable')]
    public function testSitemap(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sitemap');
        self::assertIsString($file);
        file_put_contents($file, '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.example.com/s1</loc><lastmod>2026-01-01</lastmod></url><url><loc>https://www.example.com/s2</loc><lastmod>2020-01-01</lastmod></url></urlset>');
        try {
            [$code, $output] = $this->artisanCall('indexnow:sitemap', ['sitemap' => $file, '--dry-run' => true]);
            self::assertSame(0, $code);
            self::assertStringContainsString('* https://www.example.com/s1', $output);
            self::assertStringContainsString('2 URL(s) found', $output);
            self::assertSame([], $this->transport->posts);

            [$code, $output] = $this->artisanCall('indexnow:sitemap', ['sitemap' => $file]);
            self::assertSame(0, $code);
            self::assertStringContainsString('2 URL(s) found', $output);
            self::assertSame(['https://www.example.com/s1', 'https://www.example.com/s2'], $this->sentUrls());

            [$code, $output] = $this->artisanCall('indexnow:sitemap', ['sitemap' => $file, '--changed-since' => '2021-01-01', '--json' => true]);
            self::assertSame(0, $code);
            self::assertStringContainsString('"url_count": 1', $output);

            [$code, $output] = $this->artisanCall('indexnow:sitemap', ['sitemap' => $file, '--dry-run' => true, '--json' => true]);
            self::assertSame(0, $code);
            self::assertStringContainsString('"https://www.example.com/s2"', $output);

            [$code] = $this->artisanCall('indexnow:sitemap', ['sitemap' => $file, '--changed-since' => 'not a date']);
            self::assertSame(2, $code);
        } finally {
            @unlink($file);
        }
    }

    #[TestDox('indexnow:sitemap without argument uses <base_url>/sitemap.xml and reports a fetch failure as exit 1')]
    public function testSitemapDefaultUrl(): void
    {
        $this->transport->onGet('https://www.example.com/sitemap.xml', new Response(200, '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.example.com/d1</loc></url></urlset>'));
        [$code] = $this->artisanCall('indexnow:sitemap');
        self::assertSame(0, $code);
        self::assertSame(['https://www.example.com/d1'], $this->sentUrls());

        $this->transport->onGet('https://www.example.com/broken.xml', new Response(500), new Response(500), new Response(500));
        [$code, $output] = $this->artisanCall('indexnow:sitemap', ['sitemap' => 'https://www.example.com/broken.xml']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Cannot read', $output);
    }

    private function stubKeyFiles(): void
    {
        $this->transport->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY));
        $this->transport->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: int, 1: string} exit code and output
     */
    private function artisanCall(string $command, array $arguments = []): array
    {
        $output = new BufferedOutput();
        $code = $this->app->make(Kernel::class)->call($command, $arguments, $output);

        return [$code, $output->fetch()];
    }
}
