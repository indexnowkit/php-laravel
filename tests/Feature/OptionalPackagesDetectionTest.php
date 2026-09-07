<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use IndexNowKit\Config;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Laravel\Config\ConfigFactory;
use IndexNowKit\Laravel\Tests\Fixtures\Post;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Testing\Conformance\OptionalPackageAssertions;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The optional packages left to detection (no `OptionalPackage` bound before the provider, `installed: null`): the
 * application boots, the config builds, `indexnow:check` names exactly the packages that are absent, a hook submits.
 * With the packages in `vendor/` this is the ordinary boot; the CI job `optional-packages-absent` runs it after
 * `composer remove` of the three packages, where a provider or config factory that loaded a class of the package to
 * ask about it was a fatal (`Class "IndexNowKit\Sitemap\Adapter\SitemapServices" not found`).
 */
final class OptionalPackagesDetectionTest extends LaravelTestCase
{
    #[TestDox('the config builds strictly and at runtime with detection; check names the absent packages; a hook submits')]
    public function testDetection(): void
    {
        self::assertInstanceOf(Config::class, ConfigFactory::build($this->app->make('config')->get('indexnow'), 'production'));
        self::assertTrue($this->app->make(Config::class)->enabled);

        $output = new BufferedOutput();
        $code = $this->app->make(Kernel::class)->call('indexnow:check', [], $output);
        $display = $output->fetch();
        OptionalPackageAssertions::assertDetected($display);
        self::assertContains($code, [ExitCode::SUCCESS, ExitCode::FAILURE], $display);

        Post::query()->create(['slug' => 'detected']);
        $this->kit()->flush();
        self::assertSame(['https://www.example.com/posts/detected'], $this->sentUrls());
        self::assertSame([], $this->logger->messages('critical'));
        self::assertSame([], $this->logger->messages('error'));
    }
}
