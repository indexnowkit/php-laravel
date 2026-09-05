<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use IndexNowKit\Console\Definitions;
use IndexNowKit\Console\KeyGenerateRunner;

final class KeyGenerateCommand extends Command
{
    public function __construct()
    {
        $definition = Definitions::keyGenerate('.env');
        $this->signature = $definition->laravelSignature('indexnow:key:generate');
        $this->description = $definition->description;
        parent::__construct();
    }

    public function handle(KeyGenerateRunner $runner, Application $app): int
    {
        $length = $this->option('length');
        $writeEnv = $this->option('write-env');
        $envFile = match (true) {
            !$this->hasWriteEnv() => null,
            \is_string($writeEnv) && $writeEnv !== '' => $writeEnv,
            default => $this->defaultEnvFile($app),
        };

        return $runner->run($this->getOutput(), is_numeric($length) ? (int) $length : 32, !(bool) $this->option('alphanumeric'), $envFile, (bool) $this->option('force'), (bool) $this->option('no-previous'), (bool) $this->option('yes'));
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
