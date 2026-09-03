<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use IndexNowKit\Client;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\NullDebounceStore;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Submitter;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Url\UrlNormalizerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Submitters for artisan commands: `--force` bypasses the debounce store, `--dry-run` logs instead of sending.
 * Neither touches the application's own Submitter.
 */
class SubmitterFactory
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly KeyProviderInterface $keys,
        private readonly Config $config,
        private readonly DebounceStoreInterface $debounce,
        private readonly ThrottleInterface $throttle,
        private readonly UrlNormalizerInterface $normalizer,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function create(bool $force, bool $dryRun): SubmitterInterface
    {
        $config = $dryRun ? $this->config->with(dryRun: true) : $this->config;
        $client = new Client($this->transport, $this->keys, $config, $this->logger, $this->throttle, $this->normalizer);

        return new Submitter($client, $config, $force ? new NullDebounceStore() : $this->debounce, $this->logger, $this->normalizer);
    }
}
