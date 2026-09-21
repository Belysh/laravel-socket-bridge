<?php

namespace SocketBridge\Operations;

use SocketBridge\Outbox\OutboxStore;

final class Maintenance
{
    public function __construct(private readonly StreamRetention $streams, private readonly OutboxStore $outbox) {}

    public function prune(bool $dryRun = false, int $limit = 1000): array
    {
        $limit = max(1, min($limit, 10000));
        $settings = [
            'streams_seconds' => max(1, (int) config('socket-bridge.retention.streams_seconds', 86400)),
            'dead_letters_seconds' => max(1, (int) config('socket-bridge.retention.dead_letters_seconds', 604800)),
            'receipts_seconds' => max(1, (int) config('socket-bridge.retention.receipts_seconds', 604800)),
            'published_outbox_seconds' => max(1, (int) config('socket-bridge.retention.published_outbox_seconds', 86400)),
        ];
        $report = ['dry_run' => $dryRun, 'deduplication_window_seconds' => $settings['receipts_seconds'], 'streams' => []];
        foreach (['events', 'commands'] as $stream) {
            $report['streams'][$stream] = $this->streams->prune($stream, (int) now()->subSeconds($settings['streams_seconds'])->valueOf(), $dryRun, $limit);
            $report['streams']['dead:'.$stream] = $this->streams->prune('dead:'.$stream, (int) now()->subSeconds($settings['dead_letters_seconds'])->valueOf(), $dryRun, $limit, true);
        }
        $db = $this->outbox->database();
        $receiptCutoff = now()->subSeconds($settings['receipts_seconds']);
        $receipts = $db->table('socket_bridge_command_receipts')->whereNotNull('result')->where('updated_at', '<', $receiptCutoff)->orderBy('updated_at')->limit($limit)->get(['session_id', 'command_id']);
        $receiptCount = 0;
        foreach ($receipts as $receipt) {
            $receiptCount += $db->transaction(function () use ($db, $receipt, $receiptCutoff, $dryRun): int {
                $query = $db->table('socket_bridge_command_receipts')->where('session_id', $receipt->session_id)->where('command_id', $receipt->command_id);
                $current = (clone $query)->lockForUpdate()->first();
                if ($current === null || $current->result === null || now()->parse($current->updated_at)->gte($receiptCutoff)) {
                    return 0;
                }
                // Never remove deduplication evidence before its durable result is published.
                $waiting = $db->table('socket_bridge_outbox')->whereNull('published_at')
                    ->where('envelope->type', 'socket.command.result')
                    ->where('envelope->session_id', $receipt->session_id)
                    ->whereRaw('LOWER('.$db->getQueryGrammar()->wrap('envelope->command_id').') = ?', [strtolower($receipt->command_id)])
                    ->exists();
                if ($waiting) {
                    return 0;
                }

                return $dryRun ? 1 : $query->delete();
            });
        }
        $published = $db->table('socket_bridge_outbox')->whereNotNull('published_at')
            ->where('published_at', '<', now()->subSeconds($settings['published_outbox_seconds']))->orderBy('published_at')->limit($limit)->pluck('id');
        $outboxCount = $dryRun ? $published->count() : $db->table('socket_bridge_outbox')->whereIn('id', $published)->whereNotNull('published_at')->delete();
        $report['receipts'] = ['eligible' => $receiptCount, 'deleted' => $dryRun ? 0 : $receiptCount];
        $report['published_outbox'] = ['eligible' => $outboxCount, 'deleted' => $dryRun ? 0 : $outboxCount];

        return $report;
    }
}
