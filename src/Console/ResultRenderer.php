<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Console\Command;
use IndexNowKit\Result;
use IndexNowKit\ResultStatus;

/**
 * Shared output of the submit / submit-model / sitemap commands: a table, or JSON with --json. Bind your own
 * instance to match the envelope of your other commands.
 */
class ResultRenderer
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param list<Result> $results
     *
     * @return int exit code
     */
    public function results(Command $command, array $results, bool $json): int
    {
        $failed = false;
        foreach ($results as $r) {
            $failed = $failed || $r->status === ResultStatus::Failed;
        }
        if ($json) {
            $command->getOutput()->writeln((string) json_encode(array_map($this->row(...), $results), self::JSON_FLAGS));

            return $failed ? Command::FAILURE : Command::SUCCESS;
        }
        if ($results === []) {
            $command->warn('Nothing submitted: no URL was given.');

            return Command::SUCCESS;
        }
        $rows = [];
        foreach ($results as $r) {
            $rows[] = [$r->engine, $r->host, $r->urlCount(), $r->status->value, $r->httpCode ?? '-', $r->reason !== null ? $r->reason->value : '', $r->error ?? ''];
        }
        $command->table(['engine', 'host', 'urls', 'status', 'http', 'reason', 'detail'], $rows);
        $this->allSkippedNote($command, array_map(static fn(Result $r): string => $r->status->value, $results));

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Aggregated results of a batched run (`indexnow:sitemap`).
     *
     * @return int exit code
     */
    public function summary(Command $command, ResultSummary $summary, bool $json): int
    {
        $rows = $summary->rows();
        if ($json) {
            $command->getOutput()->writeln((string) json_encode($rows, self::JSON_FLAGS));

            return $summary->failed() ? Command::FAILURE : Command::SUCCESS;
        }
        if ($rows === []) {
            $command->warn('Nothing submitted: the sitemap yielded no URL.');

            return Command::SUCCESS;
        }
        $table = [];
        foreach ($rows as $row) {
            $table[] = [$row['engine'], $row['host'], $row['url_count'], $row['batches'], $row['status'], $row['http'] ?? '-', $row['reason'] ?? '', $row['error'] ?? ''];
        }
        $command->table(['engine', 'host', 'urls', 'batches', 'status', 'http', 'reason', 'detail'], $table);
        $this->allSkippedNote($command, array_column($rows, 'status'));

        return $summary->failed() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param list<string> $statuses
     */
    private function allSkippedNote(Command $command, array $statuses): void
    {
        $skipped = array_filter($statuses, static fn(string $status): bool => $status === ResultStatus::Skipped->value);
        if ($skipped !== [] && \count($skipped) === \count($statuses)) {
            $command->line('Nothing was sent. The "reason" column says why (dry_run, disabled, debounced, no_key, invalid_url); use --force to bypass the debounce store.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Result $r): array
    {
        return ['engine' => $r->engine, 'host' => $r->host, 'status' => $r->status->value, 'reason' => $r->reason?->value, 'http' => $r->httpCode, 'retryable' => $r->retryable, 'error' => $r->error, 'urls' => $r->urls];
    }
}
