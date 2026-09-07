<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Config;
use IndexNowKit\Console\ConfigSourceInterface;
use IndexNowKit\Laravel\Config\ConfigFactory;

/**
 * What `php artisan indexnow:check` and `indexnow:config` read in a Laravel application: the `indexnow` config array
 * as the repository holds it (env() resolved), its strict build through `Config\ConfigFactory::build()` with the
 * three package predicates of the provider, and the blocks of the installed optional packages
 * (`IndexNowKitServiceProvider::PACKAGE_BLOCKS`). The one place the package hands its configuration to the commands
 * of `indexnowkit/console` (wave M, spec 19 §4.1); the provider binds it to `Console\ConfigSourceInterface`.
 */
final class ConfigSource implements ConfigSourceInterface
{
    /**
     * @param Closure(): array<string, array<string, mixed>> $packages the `PACKAGE_BLOCKS` binding: the effective block of every installed package by name
     */
    public function __construct(
        private readonly Repository $config,
        private readonly Application $app,
        private readonly OptionalPackage $sitemap,
        private readonly OptionalPackage $verify,
        private readonly OptionalPackage $history,
        private readonly Closure $packages,
    ) {}

    public function raw(): array
    {
        $raw = $this->config->get('indexnow');

        /** @var array<string, mixed> */
        return \is_array($raw) ? $raw : [];
    }

    public function build(): Config
    {
        return ConfigFactory::build($this->raw(), (string) $this->app->environment(), $this->sitemap->installed(), $this->verify->installed(), $this->history->installed());
    }

    public function packages(): array
    {
        return ($this->packages)();
    }
}
