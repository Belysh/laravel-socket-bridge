<?php

namespace SocketBridge\Operations;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use SocketBridge\Outbox\OutboxStore;

final class Maintenance
{
    public function __construct(private readonly StreamRetention $streams, private readonly OutboxStore $outbox, private readonly ?CleanupStatus $status = null) {}

    public function prune(bool $dryRun = false, ?int $limit = null, ?int $batchSize = null, ?float $maxSeconds = null): array
    {
        $limit ??= (int) config('socket-bridge.retention.limit', 10000);
        $batchSize ??= (int) config('socket-bridge.retention.batch_size', 100);
        $maxSeconds ??= (float) config('socket-bridge.retention.max_seconds', 10);
        if ($limit < 1 || $limit > 1000000 || $batchSize < 1 || $batchSize > 1000 || ! is_finite($maxSeconds) || $maxSeconds < 0.01 || $maxSeconds > 300) {
            throw new InvalidArgumentException('Cleanup requires limit 1–1000000, batch_size 1–1000 and max_seconds 0.01–300.');
        }
        $started = hrtime(true);
        $expired = static fn (): bool => (hrtime(true) - $started) / 1e9 >= $maxSeconds;
        $now = now();
        $settings = [];
        foreach (['streams_seconds' => 86400, 'dead_letters_seconds' => 604800, 'receipts_seconds' => 604800, 'published_outbox_seconds' => 86400] as $key => $default) {
            $settings[$key] = max(1, (int) config('socket-bridge.retention.'.$key, $default));
        }
        $empty = ['scanned' => 0, 'eligible' => 0, 'deleted' => 0, 'has_more' => null, 'retention_lag_seconds' => null, 'protected' => false];
        $report = ['dry_run' => $dryRun, 'limit' => $limit, 'batch_size' => $batchSize, 'max_seconds' => $maxSeconds, 'deduplication_window_seconds' => $settings['receipts_seconds'], 'streams' => []];
        $names = ['events', 'commands', 'dead:events', 'dead:commands', 'receipts', 'published_outbox'];
        $states = array_fill_keys($names, $empty);
        $cursors = array_fill_keys($names, null);
        $status = $this->status ?? app(CleanupStatus::class);
        if (! $dryRun) {
            $cursors['receipts'] = $status->receiptCursor();
        }
        $active = array_fill_keys($names, true);
        $db = $this->outbox->database();
        // Round-robin batches leave a busy stream unable to monopolize the entire time budget.
        while ($active !== [] && ! $expired()) {
            foreach (array_keys($active) as $name) {
                if ($expired()) {
                    break;
                }
                $count = min($batchSize, $limit - $states[$name]['scanned']);
                if (in_array($name, ['receipts', 'published_outbox'], true)) {
                    $cutoff = $now->copy()->subSeconds($settings[$name === 'receipts' ? 'receipts_seconds' : 'published_outbox_seconds']);
                    $batch = $name === 'receipts'
                        ? $this->receipts($db, $cutoff, $dryRun, $count, $cursors[$name], $expired)
                        : $this->published($db, $cutoff, $dryRun, $count, $cursors[$name]);
                } else {
                    $dead = str_starts_with($name, 'dead:');
                    $cutoff = (int) $now->copy()->subSeconds($settings[$dead ? 'dead_letters_seconds' : 'streams_seconds'])->valueOf();
                    $batch = $this->streams->prune($name, $cutoff, $dryRun, $count, $dead, $cursors[$name]);
                    $batch['scanned'] = $batch['eligible'];
                }
                $cursors[$name] = $batch['cursor'];
                foreach (['scanned', 'eligible', 'deleted'] as $field) {
                    $states[$name][$field] += $batch[$field];
                }
                foreach (['has_more', 'retention_lag_seconds', 'protected', 'protected_from', 'groups'] as $field) {
                    if (array_key_exists($field, $batch)) {
                        $states[$name][$field] = $field === 'protected' && $name === 'receipts'
                            ? $states[$name][$field] || $batch[$field]
                            : $batch[$field];
                    }
                }
                if (! $batch['has_more'] || $states[$name]['scanned'] >= $limit) {
                    unset($active[$name]);
                }
            }
        }
        foreach ($states as $name => $state) {
            if (in_array($name, ['receipts', 'published_outbox'], true)) {
                $report[$name] = $state;
            } else {
                $report['streams'][$name] = $state;
            }
        }
        $report['duration_seconds'] = (hrtime(true) - $started) / 1e9;
        $report['time_limit_reached'] = $active !== [] && $expired();
        if (! $dryRun) {
            // Resume past protected receipts next time; wrap only after traversing the old set.
            if ($report['receipts']['has_more'] !== null) {
                $status->rememberReceiptCursor($report['receipts']['has_more'] ? $cursors['receipts'] : null);
            }
            $status->record($report);
        }

        return $report;
    }

    private function receipts(Connection $db, mixed $cutoff, bool $dryRun, int $limit, ?array $cursor, callable $expired): array
    {
        $query = $db->table('socket_bridge_command_receipts')->whereNotNull('result')->where('updated_at', '<', $cutoff);
        $columns = ['updated_at', 'session_id', 'command_id'];
        $page = $this->after(clone $query, $columns, $cursor)->orderBy('updated_at')->orderBy('session_id')->orderBy('command_id')->limit($limit)->get($columns);
        $scanned = $eligible = $deleted = $protected = 0;
        foreach ($page as $receipt) {
            if ($expired()) {
                break;
            }
            $cursor = array_map(static fn ($column) => $receipt->{$column}, $columns);
            $scanned++;
            $result = $db->transaction(function () use ($db, $receipt, $cutoff, $dryRun): array {
                $currentQuery = $db->table('socket_bridge_command_receipts')->where('session_id', $receipt->session_id)->where('command_id', $receipt->command_id);
                $current = (clone $currentQuery)->lockForUpdate()->first();
                if ($current === null || $current->result === null || now()->parse($current->updated_at)->gte($cutoff)) {
                    return [0, 0, 0];
                }
                // Keep deduplication evidence until the durable result has been published.
                $waiting = $db->table('socket_bridge_outbox')->whereNull('published_at')
                    ->where('envelope->type', 'socket.command.result')->where('envelope->session_id', $receipt->session_id)
                    ->whereRaw('LOWER('.$db->getQueryGrammar()->wrap('envelope->command_id').') = ?', [strtolower($receipt->command_id)])->exists();
                if ($waiting) {
                    return [0, 0, 1];
                }

                return [1, $dryRun ? 0 : $currentQuery->delete(), 0];
            });
            $eligible += $result[0];
            $deleted += $result[1];
            $protected += $result[2];
        }
        $more = $this->after(clone $query, $columns, $cursor)->exists();
        $oldest = (clone $query)->orderBy('updated_at')->value('updated_at');

        return ['scanned' => $scanned, 'eligible' => $eligible, 'deleted' => $deleted, 'cursor' => $cursor, 'has_more' => $more, 'protected' => $protected > 0, 'retention_lag_seconds' => $this->lag($oldest, $cutoff)];
    }

    private function published(Connection $db, mixed $cutoff, bool $dryRun, int $limit, ?array $cursor): array
    {
        $query = $db->table('socket_bridge_outbox')->whereNotNull('published_at')->where('published_at', '<', $cutoff);
        $columns = ['published_at', 'id'];
        $page = $this->after(clone $query, $columns, $cursor)->orderBy('published_at')->orderBy('id')->limit($limit)->get($columns);
        if ($last = $page->last()) {
            $cursor = array_map(static fn ($column) => $last->{$column}, $columns);
        }
        $deleted = $dryRun ? 0 : (clone $query)->whereIn('id', $page->pluck('id'))->delete();
        $oldest = (clone $query)->orderBy('published_at')->value('published_at');

        return ['scanned' => $page->count(), 'eligible' => $page->count(), 'deleted' => $deleted, 'cursor' => $cursor, 'has_more' => $this->after(clone $query, $columns, $cursor)->exists(), 'protected' => false, 'retention_lag_seconds' => $this->lag($oldest, $cutoff)];
    }

    private function after(Builder $query, array $columns, ?array $cursor): Builder
    {
        if ($cursor !== null) {
            $query->where(function (Builder $seek) use ($columns, $cursor): void {
                foreach ($columns as $index => $column) {
                    $seek->orWhere(function (Builder $term) use ($columns, $cursor, $index, $column): void {
                        for ($previous = 0; $previous < $index; $previous++) {
                            $term->where($columns[$previous], $cursor[$previous]);
                        }
                        $term->where($column, '>', $cursor[$index]);
                    });
                }
            });
        }

        return $query;
    }

    private function lag(mixed $oldest, mixed $cutoff): int
    {
        return $oldest === null ? 0 : (int) max(0, now()->parse($oldest)->diffInSeconds($cutoff));
    }
}
