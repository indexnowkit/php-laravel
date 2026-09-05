<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use IndexNowKit\Http\Response;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Testing\CheckOutputAssertions;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A staging copy with the production key and INDEXNOW_DRY_RUN unset submits real URLs: `indexnow:check` must fail
 * on it (the application environment "testing" is not in production_environments). The shipped config file reads
 * `env('INDEXNOW_DRY_RUN')` without a default so an unset variable stays unset (null) instead of becoming false.
 */
final class StagingTest extends LaravelTestCase
{
    protected function configOverrides(): array
    {
        return ['dry_run' => null];
    }

    #[TestDox('check outside production with a key and dry_run unset -> exit 1 and the staging error')]
    public function testCheckFailsOutsideProductionWhenDryRunIsUnset(): void
    {
        $this->transport->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY));
        $this->transport->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY));
        $output = new BufferedOutput();

        $code = $this->app->make(Kernel::class)->call('indexnow:check', [], $output);
        $display = $output->fetch();

        CheckOutputAssertions::assertExitCode(1, $code, $display);
        self::assertStringContainsString('✘ environment "testing" is not in production_environments but dry_run is off: changes WILL be sent to search engines under key', $display);
        self::assertStringContainsString('Set INDEXNOW_DRY_RUN=1 or INDEXNOW_ENABLED=0 outside production', $display);
        self::assertStringNotContainsString(self::KEY, $display, 'the key is masked');
    }
}
