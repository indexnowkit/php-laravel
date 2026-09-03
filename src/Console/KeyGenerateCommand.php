<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use IndexNowKit\Console\KeyGenerateRunner;

final class KeyGenerateCommand extends Command
{
    protected $signature = 'indexnow:key:generate
        {--l|length=32 : Key length (8-128)}
        {--alphanumeric : Use the full alphanumeric alphabet instead of hex}
        {--write-env= : Write INDEXNOW_KEY=<key> to this env file (default .env); idempotent}
        {--force : Replace an existing INDEXNOW_KEY line in the env file (key rotation)}';

    protected $description = 'Generate a new IndexNow key (optionally write INDEXNOW_KEY to .env)';

    public function handle(KeyGenerateRunner $runner, Application $app): int
    {
        $length = $this->option('length');
        $writeEnv = $this->option('write-env');
        $envFile = match (true) {
            !$this->hasWriteEnv() => null,
            \is_string($writeEnv) && $writeEnv !== '' => $writeEnv,
            default => $this->defaultEnvFile($app),
        };

        return $runner->run($this->getOutput(), is_numeric($length) ? (int) $length : 32, !(bool) $this->option('alphanumeric'), $envFile, (bool) $this->option('force'));
    }

    /** `--write-env` without a value arrives as null, the same as "not given"; the raw input tells them apart. */
    private function hasWriteEnv(): bool
    {
        return $this->input->hasParameterOption('--write-env');
    }

    private function defaultEnvFile(Application $app): string
    {
        return $app instanceof \Illuminate\Foundation\Application ? $app->environmentFilePath() : $app->basePath('.env');
    }
}
