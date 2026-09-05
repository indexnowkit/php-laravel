<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Check;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;

/**
 * Whether model changes reach IndexNow on their own: the observer is registered only with `eloquent.enabled` and
 * `enabled` both on. Printed by `indexnow:check` after the built-in lines.
 */
final class EloquentCheck implements CheckInterface
{
    public function __construct(private readonly bool $observing) {}

    public function check(CheckReport $report): void
    {
        if ($this->observing) {
            $report->ok('eloquent: models using IndexNowable (or registered with IndexNowKit::observe()) are submitted automatically after commit', 'eloquent.enabled');
        } else {
            $report->warning('eloquent: model observers are NOT active (eloquent.enabled or enabled is false); use indexnow:submit or IndexNowKit::submit()', 'eloquent.enabled');
        }
    }
}
