<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Check;

use Closure;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;

/**
 * The `--sample` / `--sample-class` line(s) of `indexnow:check`. With `indexnowkit/verify` the samples go to its
 * `Check\SampleCheck` (built at check time, with the options of the running command); without the package a sample
 * is an error naming the install line and no sample is the plain "not installed" line.
 */
final class VerifySampleCheck implements CheckInterface
{
    /**
     * @param (Closure(list<string>, list<string>): CheckInterface)|null $factory builds the package's `SampleCheck` over the
     *                                                                        `--sample` URLs and `--sample-class` specs; null without the package
     * @param string|null                                                 $missing the `check` line without the package (`OptionalPackage::checkLine()`)
     * @param CheckLevel|null                                             $level   its level (`OptionalPackage::checkLevel()`)
     */
    public function __construct(private readonly SampleOptions $options, private readonly ?Closure $factory, private readonly ?string $missing = null, private readonly ?CheckLevel $level = null) {}

    public function check(CheckReport $report): void
    {
        if ($this->factory !== null) {
            ($this->factory)($this->options->urls, $this->options->classes)->check($report);

            return;
        }
        if (!$this->options->isEmpty()) {
            $report->error('check --sample needs indexnowkit/verify (composer require indexnowkit/verify)', 'verify.installed');

            return;
        }
        $line = ($this->missing ?? 'verify: not installed (composer require indexnowkit/verify)') . ' — pre-flight checks off';
        if ($this->level === CheckLevel::Warning) {
            $report->warning($line, 'verify.installed');
        } else {
            $report->ok($line, 'verify.installed');
        }
    }
}
