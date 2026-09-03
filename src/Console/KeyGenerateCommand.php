<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use IndexNowKit\Key\KeyGenerator;

final class KeyGenerateCommand extends Command
{
    protected $signature = 'indexnow:key:generate
        {--l|length=32 : Key length (8-128)}
        {--alphanumeric : Use the full alphanumeric alphabet instead of hex}
        {--write-env= : Write INDEXNOW_KEY=<key> to this env file (default .env); idempotent}
        {--force : Replace an existing INDEXNOW_KEY line in the env file (key rotation)}';

    protected $description = 'Generate a new IndexNow key (optionally write INDEXNOW_KEY to .env)';

    public function handle(Application $app): int
    {
        $length = $this->option('length');
        $key = KeyGenerator::generate(is_numeric($length) ? (int) $length : 32, !(bool) $this->option('alphanumeric'));

        if (!$this->hasWriteEnv()) {
            $this->line($key);
            $this->newLine();
            $this->line('Add to your environment:');
            $this->line('  INDEXNOW_KEY=' . $key);
            $this->line('Then run: php artisan indexnow:check');

            return self::SUCCESS;
        }

        $writeEnv = $this->option('write-env');
        $file = \is_string($writeEnv) && $writeEnv !== '' ? $writeEnv : $this->defaultEnvFile($app);
        $contents = is_file($file) ? (string) file_get_contents($file) : '';
        $line = 'INDEXNOW_KEY=' . $key;
        if (preg_match('/^\s*INDEXNOW_KEY\s*=/m', $contents) === 1) {
            if (!(bool) $this->option('force')) {
                $this->info(\sprintf('%s already defines INDEXNOW_KEY, nothing to do (use --force to rotate the key).', $file));

                return self::SUCCESS;
            }
            $contents = (string) preg_replace('/^(\s*)INDEXNOW_KEY\s*=.*$/m', '$1' . $line, $contents, 1);
            $this->warn('Rotating the key: submissions fail with 403 until the new key file is reachable (CDN caches!). Run indexnow:check afterwards.');
        } else {
            $contents .= ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n") . $line . "\n";
        }
        if (file_put_contents($file, $contents) === false) {
            $this->error(\sprintf('Cannot write %s.', $file));

            return self::FAILURE;
        }
        $this->info(\sprintf('INDEXNOW_KEY written to %s.', $file));
        $this->line('The key file is served at /<key>.txt by the package route. Verify with: php artisan indexnow:check');

        return self::SUCCESS;
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
