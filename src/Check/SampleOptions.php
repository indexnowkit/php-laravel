<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Check;

/**
 * The `--sample` and `--sample-class` values of the running `indexnow:check`, filled by the command before the
 * checker runs: the checks are container singletons, the options are known at run time.
 */
final class SampleOptions
{
    /** @var list<string> */
    public array $urls = [];
    /** @var list<string> */
    public array $classes = [];

    public function isEmpty(): bool
    {
        return $this->urls === [] && $this->classes === [];
    }
}
