# Contributing

This repository is a read-only split of [`indexnowkit/php`](https://github.com/indexnowkit/php) (`packages/laravel`).
Please open issues and pull requests there; releases are tagged in the monorepo as `laravel@x.y.z` and mirrored here.

Quick rules (details in the monorepo's CONTRIBUTING.md):

- Every configuration key gets a line in `docs/configuration.md` and a test (`tests/Feature/`, config overrides through
  `LaravelTestCase::configOverrides()`).
- Nothing may throw from an observer, `app()->terminating()` or the queue job into the application: log on the
  IndexNow channel instead.
- Laravel 12 and 13 both stay green; the core conformance kits (`tests/Conformance/`) must pass unchanged.
- phpstan level 9 (+ larastan) and php-cs-fixer must pass. PHPUnit, not Pest.
