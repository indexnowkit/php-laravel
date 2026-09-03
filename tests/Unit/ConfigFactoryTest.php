<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Unit;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Laravel\Config\ConfigFactory;
use IndexNowKit\Testing\ArrayLogger;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class ConfigFactoryTest extends TestCase
{
    private const KEY = 'abcdef1234567890abcdef1234567890';

    #[TestDox('coreOptions strips the Laravel blocks and maps key_file.enabled to serve_key_file')]
    public function testCoreOptions(): void
    {
        $core = ConfigFactory::coreOptions([
            'key' => self::KEY,
            'queue' => ['connection' => 'redis'],
            'key_file' => ['enabled' => false, 'path' => '/{key}.txt'],
            'router' => ['locales' => ['en']],
            'eloquent' => ['enabled' => true],
            'sitemap' => ['enabled' => true],
            'logging' => ['channel' => 'stack', 'max_urls' => 5],
            'debounce' => ['store' => 'redis'],
            'http' => ['client' => 'x'],
            'environment' => '',
        ]);

        self::assertSame(['key' => self::KEY, 'logging' => ['max_urls' => 5], 'serve_key_file' => false], $core);
        self::assertSame([], Config::unknownOptions($core));
        self::assertTrue(ConfigFactory::coreOptions(['serve_key_file' => true, 'key_file' => ['enabled' => false]])['serve_key_file'], 'an explicit serve_key_file wins');
    }

    #[TestDox('the application environment feeds the core; dispatch queue requires base_url; unknown dispatch is rejected')]
    public function testBuild(): void
    {
        $config = ConfigFactory::build(['key' => self::KEY, 'base_url' => 'https://www.example.com', 'dispatch' => 'queue'], 'production');
        self::assertSame('production', $config->environment);
        self::assertTrue($config->isProduction());

        try {
            ConfigFactory::build(['key' => self::KEY, 'dispatch' => 'queue'], 'production');
            self::fail('expected an exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('base_url', $e->getMessage());
        }
        $this->expectException(ConfigurationException::class);
        ConfigFactory::build(['key' => self::KEY, 'dispatch' => 'messenger'], 'production');
    }

    #[TestDox('create() never throws: a bad value is logged at critical and IndexNow runs disabled; unknown keys are warned')]
    public function testCreateFallsBackToDisabled(): void
    {
        $logger = new ArrayLogger();
        $config = ConfigFactory::create(['key' => 'bad', 'nope' => 1], 'local', $logger);

        self::assertFalse($config->enabled);
        self::assertTrue($config->dryRun);
        self::assertSame('local', $config->environment);
        self::assertStringContainsString('disabled until it is fixed', implode("\n", $logger->messages('critical')));
        self::assertStringContainsString('nope', implode("\n", $logger->messages('warning')));
    }

    #[TestDox('outside production a missing key turns dry_run on instead of failing')]
    public function testDryRunSafetyNet(): void
    {
        $config = ConfigFactory::create(['base_url' => 'https://www.example.com', 'dispatch' => 'sync'], 'local', new ArrayLogger());

        self::assertTrue($config->enabled);
        self::assertTrue($config->dryRun);
    }
}
