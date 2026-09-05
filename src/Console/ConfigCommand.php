<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Config;
use IndexNowKit\Console\ConfigRunner;
use IndexNowKit\Console\Definitions;
use IndexNowKit\Laravel\Config\ConfigFactory;
use IndexNowKit\Laravel\IndexNowKitServiceProvider;

final class ConfigCommand extends Command
{
    public function __construct()
    {
        $definition = Definitions::config();
        $this->signature = $definition->laravelSignature('indexnow:config');
        $this->description = $definition->description;
        parent::__construct();
    }

    public function handle(ConfigRunner $runner, Repository $config, Application $app): int
    {
        $raw = $config->get('indexnow');
        $raw = \is_array($raw) ? $raw : [];

        return $runner->run(
            $this->getOutput(),
            static function () use ($raw, $app): Config {
                $package = $app->make(IndexNowKitServiceProvider::SITEMAP_PACKAGE);
                \assert($package instanceof OptionalPackage);

                return ConfigFactory::build($raw, (string) $app->environment(), $package->installed());
            },
            $raw,
            (bool) $this->option('json'),
        );
    }
}
