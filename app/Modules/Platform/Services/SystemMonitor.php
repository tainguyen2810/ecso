<?php

namespace App\Modules\Platform\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Platform health for super admins and alerts. Counts and job/exception class names only: no tenant business
 * data (SECURITY.md — super admins do not see tenant data without an audited support session).
 */
class SystemMonitor
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $queues = [];
        foreach ((array) config('ecso.monitoring.queues') as $name) {
            try {
                $queues[$name] = Queue::connection()->size($name);
            } catch (Throwable) {
                $queues[$name] = null;
            }
        }

        $heartbeat = Cache::get('ecso:scheduler:heartbeat');
        $since = now()->subDay();

        $recentFailed = DB::table('failed_jobs')->orderByDesc('id')->limit(20)->get(['id', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'queue' => $row->queue,
                'job' => class_basename((string) (json_decode($row->payload, true)['displayName'] ?? 'unknown')),
                // Exception class only: messages may contain tenant data.
                'exception' => class_basename(trim(strtok((string) $row->exception, ':') ?: 'Exception')),
                'failed_at' => $row->failed_at,
            ]);

        return [
            'checked_at' => now()->toIso8601String(),
            'queue_connection' => config('queue.default'),
            'queues' => $queues,
            'failed_jobs_total' => DB::table('failed_jobs')->count(),
            'failed_jobs_24h' => DB::table('failed_jobs')->where('failed_at', '>=', $since)->count(),
            'failed_jobs_recent' => $recentFailed,
            'scheduler_last_run' => is_numeric($heartbeat) ? date(DATE_ATOM, (int) $heartbeat) : null,
            'scheduler_ok' => is_numeric($heartbeat) && (int) $heartbeat >= now()->subMinutes((int) config('ecso.monitoring.scheduler_stale_minutes'))->timestamp,
            'webhooks_24h' => DB::table('webhook_events')->where('received_at', '>=', $since)
                ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n),
            'outbox' => DB::table('inventory_sync_outbox')->whereIn('status', ['pending', 'failed'])
                ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n),
            'circuits_open' => DB::table('marketplace_accounts')->where('circuit_open_until', '>', now())->count(),
            'disk_free_percent' => $this->diskFreePercent(),
        ];
    }

    /**
     * Problems worth paging someone about.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    public function problems(array $snapshot): array
    {
        $problems = [];
        foreach ($snapshot['queues'] as $queue => $size) {
            if ($size !== null && $size > (int) config('ecso.monitoring.backlog_alert')) {
                $problems[] = "Queue {$queue} tồn {$size} job";
            }
        }
        if ($snapshot['failed_jobs_24h'] > (int) config('ecso.monitoring.failed_jobs_alert_24h')) {
            $problems[] = "{$snapshot['failed_jobs_24h']} job lỗi trong 24 giờ";
        }
        if (! $snapshot['scheduler_ok']) {
            $problems[] = 'Scheduler không chạy (không có heartbeat)';
        }
        if ($snapshot['circuits_open'] > 0) {
            $problems[] = "{$snapshot['circuits_open']} shop đang ngắt mạch đồng bộ tồn";
        }
        if ($snapshot['disk_free_percent'] !== null && $snapshot['disk_free_percent'] < 10) {
            $problems[] = "Ổ đĩa còn {$snapshot['disk_free_percent']}%";
        }

        return $problems;
    }

    private function diskFreePercent(): ?float
    {
        $total = @disk_total_space(storage_path());
        $free = @disk_free_space(storage_path());

        return $total && $free !== false ? round($free / $total * 100, 1) : null;
    }
}
