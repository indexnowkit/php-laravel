<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Check;

use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Check\SampleOptions;
use Throwable;

/**
 * The locale side of the router bridge: `#[IndexNow(locales: 'all')]` expands to `router.locales`, and an empty
 * list quietly collapses it to a single URL in the current locale. With locales configured the line names them;
 * with none it stays silent unless a rule the command can see (`--sample-class=<FQCN>`) asks for `'all'`, which is
 * one warning naming `router.locales`. At run time the same situation is one warning per process from
 * {@see \IndexNowKit\Laravel\Url\LaravelRouteUrlResolver}.
 */
final class RouterCheck implements CheckInterface
{
    public const CODE = 'router.locales';

    /**
     * @param list<string> $locales `router.locales`
     */
    public function __construct(
        private readonly array $locales,
        private readonly AttributeReaderInterface $rules,
        private readonly SampleOptions $samples,
        private readonly string $localeParameter = 'locale',
    ) {}

    public function check(CheckReport $report): void
    {
        if ($this->locales !== []) {
            $report->ok(\sprintf('router.locales: %s — a rule with locales: \'all\' generates one URL per locale (route parameter "%s")', implode(', ', $this->locales), $this->localeParameter), self::CODE);

            return;
        }
        $asking = $this->classesAskingForAllLocales();
        if ($asking === []) {
            return;
        }
        $report->warning(\sprintf('router.locales is empty, but %s has a rule with locales: \'all\': one URL in the current locale is generated instead of one per locale. List the locales in router.locales.', implode(', ', $asking)), self::CODE);
    }

    /**
     * Classes among the `--sample-class` values whose rules ask for every locale.
     *
     * @return list<string>
     */
    private function classesAskingForAllLocales(): array
    {
        $asking = [];
        foreach ($this->samples->classes as $spec) {
            $class = str_contains($spec, ':') ? strstr($spec, ':', true) : $spec;
            if (!\is_string($class) || !class_exists($class)) {
                continue;
            }
            try {
                foreach ($this->rules->rules($class) as $rule) {
                    if ($rule->locales === 'all') {
                        $asking[] = $class;

                        break;
                    }
                }
            } catch (Throwable) {
                // an invalid #[IndexNow] is the business of the rule reader, not of this line
                continue;
            }
        }

        return array_values(array_unique($asking));
    }
}
