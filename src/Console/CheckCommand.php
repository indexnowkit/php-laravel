<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use IndexNowKit\Check\CheckerInterface;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Laravel\Config\ConfigFactory;

final class CheckCommand extends Command
{
    protected $signature = 'indexnow:check
        {--live : Send a real probe request (site root URL) to every configured engine}
        {--host= : Check only this host (multi-domain setups)}
        {--probe-url= : Page to send with --live (default: https://<host>/; give a real page when the root redirects)}';

    protected $description = 'Validate the IndexNow configuration, verify the key file is reachable, report how submissions are wired';

    public function handle(CheckerInterface $checker, Repository $config, Application $app): int
    {
        $this->line('<options=bold>IndexNow check</>');
        $this->newLine();
        $raw = $config->get('indexnow');
        try {
            ConfigFactory::build(\is_array($raw) ? $raw : [], (string) $app->environment());
        } catch (ConfigurationException $e) {
            $this->line('  <fg=red>✘</> configuration: ' . $e->getMessage());
            $this->newLine();
            $this->error('IndexNow is disabled until the configuration is fixed (see config/indexnow.php and INDEXNOW_* env vars).');

            return self::FAILURE;
        }

        $host = $this->option('host');
        $probeUrl = $this->option('probe-url');
        $report = $checker->run(
            liveProbe: (bool) $this->option('live'),
            onlyHost: \is_string($host) && $host !== '' ? $host : null,
            probeUrl: \is_string($probeUrl) && $probeUrl !== '' ? $probeUrl : null,
        );
        foreach ($report->items() as $item) {
            $this->line(match ($item->level) {
                CheckLevel::Ok => '  <fg=green>✔</> ' . $item->message,
                CheckLevel::Warning => '  <fg=yellow>!</> ' . $item->message,
                CheckLevel::Error => '  <fg=red>✘</> ' . $item->message,
            });
        }
        $eloquent = $config->get('indexnow.eloquent.enabled');
        $this->line($eloquent !== false && $config->get('indexnow.enabled') !== false
            ? '  <fg=green>✔</> eloquent: models using IndexNowable (or registered with IndexNowKit::observe()) are submitted automatically after commit'
            : '  <fg=yellow>!</> eloquent: model observers are NOT active (eloquent.enabled or enabled is false); use indexnow:submit or IndexNowKit::submit()');
        $this->newLine();
        if ($report->hasErrors()) {
            $this->error('IndexNow is not ready. Fix the errors above.');

            return self::FAILURE;
        }
        $this->info('IndexNow is ready.');

        return self::SUCCESS;
    }
}
