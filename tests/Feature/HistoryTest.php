<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\History\Pdo\PdoSubmissionStore;
use IndexNowKit\History\Pdo\Schema;
use IndexNowKit\Http\Response;
use IndexNowKit\Laravel\Tests\Fixtures\Post;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Submission\SubmissionStoreInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * indexnowkit/history installed with `history.store: pdo` over the default database connection and `dispatch: sync`:
 * the `SubmissionStoreInterface` binding is the PDO store, a flush lands in the table, `indexnow:history` lists it
 * (table, --json, filters, --purge), `check` / `status` / `config --json` / `about` report the store — and a missing
 * table is one `check` error, not a broken flush.
 */
final class HistoryTest extends LaravelTestCase
{
    protected function configOverrides(): array
    {
        return ['history' => ['store' => 'pdo']];
    }

    #[TestDox('a sync flush is recorded in the pdo store and listed by indexnow:history')]
    public function testFlushIsRecordedAndListed(): void
    {
        $this->createHistoryTable();
        self::assertInstanceOf(PdoSubmissionStore::class, $this->app->make(SubmissionStoreInterface::class));

        Post::query()->create(['slug' => 'recorded']);
        $this->kit()->flush();
        self::assertSame(['https://www.example.com/posts/recorded'], $this->sentUrls());

        [$code, $output] = $this->artisanCall('indexnow:history');
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('https://www.example.com/posts/recorded', $output);
        self::assertStringContainsString('1 record(s)', $output);

        [$code, $output] = $this->artisanCall('indexnow:history', ['--json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded['records']);
        self::assertSame(['ok', 'api', 200, ['https://www.example.com/posts/recorded']], [$decoded['records'][0]['status'], $decoded['records'][0]['engine'], $decoded['records'][0]['http_status'], $decoded['records'][0]['urls']]);

        [, $output] = $this->artisanCall('indexnow:history', ['--url' => 'https://www.example.com/posts/recorded?utm_source=x']);
        self::assertStringContainsString('1 record(s)', $output, '--url is normalized the way the submission was');
        [, $output] = $this->artisanCall('indexnow:history', ['--status' => 'skipped']);
        self::assertStringContainsString('No records match.', $output);
        [$code, $output] = $this->artisanCall('indexnow:history', ['--purge' => null]);
        self::assertSame(ExitCode::SUCCESS, $code);
        self::assertMatchesRegularExpression('/^purged 0 records older than \d{4}-/', trim($output));
        [, $output] = $this->artisanCall('indexnow:history', ['--purge' => '7']);
        self::assertStringContainsString('purged 0 records older than', $output);
    }

    public function testCheckStatusConfigAndAboutReportTheStore(): void
    {
        $this->createHistoryTable();
        $this->transport
            ->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY, headers: ['Content-Type' => 'text/plain']));

        [$code, $output] = $this->artisanCall('indexnow:check');
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('history: pdo store (indexnow_submissions)', $output);
        self::assertStringContainsString('history: no records yet', $output);

        [$code, $output] = $this->artisanCall('indexnow:status');
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('dispatch: sync', $output);
        self::assertStringContainsString('www.example.com: 0 consecutive 403', $output);
        self::assertStringContainsString('history: 0 records, no successful submission recorded', $output);

        [$code, $output] = $this->artisanCall('indexnow:status', ['--json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertStatusFollowsTheSchema($decoded);
        self::assertSame(['mode' => 'sync', 'adapter' => []], $decoded['dispatch']);
        self::assertSame('memory', $decoded['debounce']['store']);
        self::assertSame(['store' => 'history', 'records' => 0, 'last_success' => null, 'error' => null], $decoded['history']);

        [$code, $output] = $this->artisanCall('indexnow:config', ['--json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(['store' => 'pdo', 'limit' => 500, 'key_prefix' => null, 'pdo' => ['dsn' => null, 'service' => null, 'table' => 'indexnow_submissions'], 'retention_days' => 90], $decoded['history']);
        self::assertArrayNotHasKey('history', $decoded['adapter']);

        [, $output] = $this->artisanCall('about', ['--only' => 'indexnow']);
        self::assertStringContainsString('pdo (indexnow_submissions), 0 records', $output);
    }

    #[TestDox('without the table, the flush still sends and check prints the history.store error with the migration hint')]
    public function testMissingTableIsACheckError(): void
    {
        Post::query()->create(['slug' => 'untabled']);
        $this->kit()->flush();
        self::assertSame(['https://www.example.com/posts/untabled'], $this->sentUrls());
        self::assertNotSame([], $this->logger->messages('error'), 'the submitter logs the failing store');

        $this->transport
            ->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY, headers: ['Content-Type' => 'text/plain']));
        [$code, $output] = $this->artisanCall('indexnow:check', ['--json' => true]);
        self::assertSame(ExitCode::FAILURE, $code);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $items = array_values(array_filter($decoded['items'], static fn(array $i): bool => $i['code'] === 'history.store'));
        self::assertCount(1, $items);
        self::assertSame('error', $items[0]['level']);
        self::assertStringContainsString('docs/migrations.md', $items[0]['message']);

        [, $output] = $this->artisanCall('about', ['--only' => 'indexnow']);
        self::assertStringContainsString('store failed', $output);
    }

    private function createHistoryTable(): void
    {
        $pdo = $this->app->make(DatabaseManager::class)->connection()->getPdo();
        foreach (Schema::sql('sqlite') as $sql) {
            $pdo->exec($sql);
        }
    }

    /**
     * The required members of packages/history/docs/status.schema.json, top level and one level down (the schema
     * validator lives in the history package's tests; here the shape of what the adapter wires is enough).
     *
     * @param array<string, mixed> $status
     */
    public static function assertStatusFollowsTheSchema(array $status): void
    {
        $schema = json_decode((string) file_get_contents(\dirname(__DIR__, 3) . '/history/docs/status.schema.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);
        foreach ($schema['required'] as $key) {
            self::assertArrayHasKey($key, $status);
        }
        foreach (['dispatch', 'debounce', 'history'] as $section) {
            self::assertIsArray($status[$section]);
            foreach ($schema['properties'][$section]['required'] as $key) {
                self::assertArrayHasKey($key, $status[$section]);
            }
        }
        foreach ($status['hosts'] as $host) {
            self::assertSame(['host', 'forbidden', 'escalated'], array_keys($host));
        }
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function artisanCall(string $command, array $arguments = []): array
    {
        $output = new BufferedOutput();
        $code = $this->app->make(Kernel::class)->call($command, $arguments, $output);

        return [$code, $output->fetch()];
    }
}
