<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\Definitions;
use IndexNowKit\Laravel\Config\ConfigFactory;
use IndexNowKit\Laravel\IndexNowKitServiceProvider;

final class CheckCommand extends Command
{
    public function __construct()
    {
        $definition = Definitions::check();
        $this->signature = $definition->laravelSignature('indexnow:check');
        $this->description = $definition->description;
        parent::__construct();
    }

    public function handle(CheckRunner $runner, Repository $config, Application $app): int
    {
        $hosts = $this->option('host');
        $probeUrl = $this->option('probe-url');

        return $runner->run(
            $this->getOutput(),
            static function () use ($config, $app): mixed {
                $raw = $config->get('indexnow');

                $package = $app->make(IndexNowKitServiceProvider::SITEMAP_PACKAGE);
                \assert($package instanceof OptionalPackage);

                return ConfigFactory::build(\is_array($raw) ? $raw : [], (string) $app->environment(), $package->installed());
            },
            (bool) $this->option('live'),
            \is_array($hosts) ? array_values(array_filter($hosts, 'is_string')) : (\is_string($hosts) ? $hosts : null),
            \is_string($probeUrl) ? $probeUrl : null,
            (bool) $this->option('json'),
            (bool) $this->option('strict'),
        );
    }
}
