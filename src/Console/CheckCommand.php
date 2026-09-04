<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Console\Definitions;
use IndexNowKit\Laravel\Config\ConfigFactory;

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
        $host = $this->option('host');
        $probeUrl = $this->option('probe-url');

        return $runner->run(
            $this->getOutput(),
            static function () use ($config, $app): mixed {
                $raw = $config->get('indexnow');

                return ConfigFactory::build(\is_array($raw) ? $raw : [], (string) $app->environment());
            },
            (bool) $this->option('live'),
            \is_string($host) ? $host : null,
            \is_string($probeUrl) ? $probeUrl : null,
        );
    }
}
