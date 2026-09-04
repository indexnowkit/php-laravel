<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Check;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Config\Repository;

/**
 * The probe of the core's `Check\DebounceStoreCheck` for Laravel: touches the cache store `debounce.store` names
 * (`cache` = the default store) and returns its signature, or lets the store's exception through.
 */
final class CacheStoreProbe
{
    public function __construct(private readonly Factory $cache, private readonly Repository $config) {}

    public function __invoke(string $store): string
    {
        $name = $store === 'cache' ? null : $store;
        $repository = $this->cache->store($name);
        $repository->get('indexnowkit:check');
        $label = $name ?? (\is_string($default = $this->config->get('cache.default')) ? $default : 'default');

        return \sprintf('cache store "%s" (%s)', $label, get_debug_type($repository->getStore()));
    }
}
