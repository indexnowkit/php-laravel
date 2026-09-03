<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Check;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Config\Repository;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;
use Throwable;

/**
 * The debounce window lives in a cache store; a misconfigured store fails open (URLs are still sent) but silently
 * disables the window, so it is worth one line in `indexnow:check`.
 */
final class CacheStoreCheck implements CheckInterface
{
    public function __construct(private readonly Repository $config, private readonly Factory $cache) {}

    public function check(CheckReport $report): void
    {
        $perUrl = $this->config->get('indexnow.debounce.per_url');
        if (is_numeric($perUrl) && (int) $perUrl <= 0) {
            $report->ok('debounce: off (debounce.per_url = 0)');

            return;
        }
        $store = $this->config->get('indexnow.debounce.store');
        $store = \is_string($store) && $store !== '' ? $store : 'cache';
        if ($store === 'memory') {
            $report->warning('debounce: store "memory" is per process; web requests and queue workers do not share the window. Use a cache store in production.');

            return;
        }
        if ($store === 'none') {
            $report->ok('debounce: off (debounce.store = none)');

            return;
        }
        $name = $store === 'cache' ? null : $store;
        try {
            $repository = $this->cache->store($name);
            $repository->get('indexnowkit:check');
            $label = $name ?? (\is_string($default = $this->config->get('cache.default')) ? $default : 'default');
            $report->ok(\sprintf('debounce: %ss per URL, shared through cache store "%s"', is_numeric($perUrl) ? (int) $perUrl : 600, $label));
        } catch (Throwable $e) {
            $report->error(\sprintf('debounce: cache store "%s" is not usable (%s); URLs are still sent, the window is not applied.', $name ?? 'default', $e->getMessage()));
        }
    }
}
