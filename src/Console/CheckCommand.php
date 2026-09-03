<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use IndexNowKit\Console\CheckRunner;
use IndexNowKit\Laravel\Config\ConfigFactory;

final class CheckCommand extends Command
{
    protected $signature = 'indexnow:check
        {--live : Send a real probe request (site root URL) to every configured engine}
        {--host= : Check only this host (multi-domain setups)}
        {--probe-url= : Page to send with --live (default: https://<host>/; give a real page when the root redirects)}';

    protected $description = 'Validate the IndexNow configuration, verify the key file is reachable, report how submissions are wired';

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
