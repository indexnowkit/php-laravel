<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use IndexNowKit\Console\Command\CheckCommand;
use IndexNowKit\Console\Command\ExplainCommand;
use IndexNowKit\Console\Command\SubmitSubjectsCommand;
use IndexNowKit\Console\ConfigSourceInterface;
use IndexNowKit\Laravel\Console\ConfigSource;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Sitemap\Console\SitemapCommand;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Wave M (spec 19 §4.1): artisan runs the command classes of `indexnowkit/console`, `indexnowkit/sitemap` and
 * `indexnowkit/history`, registered by class through `Illuminate\Console\Application::resolve()` (lazy by
 * `#[AsCommand]` name) and, for `indexnow:submit-model` (named by the vocabulary), through a `LazyCommand`.
 */
final class ArtisanCommandsRegistrationTest extends LaravelTestCase
{
    #[TestDox('php artisan list names every indexnow command, touches no transport and does not build submit-model')]
    public function testListIsLazy(): void
    {
        $output = new BufferedOutput();
        $kernel = $this->app->make(Kernel::class);

        self::assertSame(0, $kernel->call('list', ['namespace' => 'indexnow'], $output));

        $listed = $output->fetch();
        foreach (['indexnow:check', 'indexnow:config', 'indexnow:submit', 'indexnow:submit-model', 'indexnow:explain', 'indexnow:key:generate', 'indexnow:sitemap', 'indexnow:history', 'indexnow:status'] as $name) {
            self::assertStringContainsString($name, $listed);
        }
        self::assertStringContainsString('Resolve the URLs of models through their #[IndexNow] rules', $listed, 'the description of submit-model comes from the definition without building the command');
        self::assertSame([], $this->transport->posts);
        self::assertSame([], $this->transport->gets);
        self::assertFalse($this->app->resolved(SubmitSubjectsCommand::class), 'submit-model is a LazyCommand: built on first run only (the #[AsCommand] classes are built by list for their descriptions, as in the bundle and Yii3)');
    }

    #[TestDox('the commands are the classes of the packages, bound in the container: submit-model through a LazyCommand with the model argument, sitemap the package\'s SitemapCommand')]
    public function testCommandClasses(): void
    {
        $artisan = $this->app->make(Kernel::class);
        \assert(method_exists($artisan, 'all'));
        $commands = $artisan->all();

        self::assertInstanceOf(LazyCommand::class, $commands['indexnow:submit-model']);
        self::assertInstanceOf(CheckCommand::class, $commands['indexnow:check']);
        self::assertInstanceOf(SitemapCommand::class, $commands['indexnow:sitemap']);

        $submitModel = $this->app->make(SubmitSubjectsCommand::class);
        self::assertSame(['model', 'ids'], array_keys($submitModel->getDefinition()->getArguments()), 'the class argument keeps its artisan name');
        self::assertSame(['model', 'id'], array_keys($this->app->make(ExplainCommand::class)->getDefinition()->getArguments()));
    }

    #[TestDox('ConfigSource reads the indexnow config array, builds it strictly and lists the blocks of the installed packages')]
    public function testConfigSource(): void
    {
        $source = $this->app->make(ConfigSourceInterface::class);
        self::assertInstanceOf(ConfigSource::class, $source);

        $raw = $source->raw();
        self::assertSame('https://www.example.com', $raw['base_url']);
        self::assertSame(['en', 'de'], $raw['router']['locales'] ?? null);

        $config = $source->build();
        self::assertSame('https://www.example.com', $config->baseUrl);
        self::assertTrue($config->enabled);

        $packages = $source->packages();
        self::assertArrayHasKey('verify', $packages, 'indexnowkit/verify is installed in the test suite');
        self::assertArrayHasKey('history', $packages);
        self::assertIsArray($packages['verify']);
    }
}
