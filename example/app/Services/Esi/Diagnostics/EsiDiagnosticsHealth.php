<?php

namespace App\Services\Esi\Diagnostics;

use App\Services\Esi\Diagnostics\Streams\EsiDiagnosticsStream;
use Illuminate\Support\Facades\DB;

/** Collector health, so a quiet chart with a broken collector reads as incomplete rather than clean. */
class EsiDiagnosticsHealth
{
    public function __construct(private EsiDiagnosticsStream $stream) {}

    public function report(): array
    {
        $checkpoint = DB::table('esi_telemetry_checkpoints')->where('name', EsiDiagnosticsFlusher::CHECKPOINT)->first();
        $backlog = null;
        $oldestAge = null;
        $bufferError = null;
        try {
            $backlog = $this->stream->length();
            $oldest = $this->stream->oldestId();
            $oldestAge = $oldest === null ? 0 : max(0, (int) floor(microtime(true) - StreamId::milliseconds($oldest) / 1000));
        } catch (\Throwable $e) {
            $bufferError = 'unreachable';
        }
        $lastFlushed = $checkpoint?->last_flushed_at;
        $staleAfter = (int) config('esi_diagnostics.health.stale_seconds', 300);
        $flushAge = $lastFlushed === null ? null : max(0, time() - strtotime($lastFlushed.' UTC'));
        $cap = (int) config('esi_diagnostics.redis.max_entries', 100000);
        $problems = [];
        if ($bufferError !== null) {
            $problems[] = 'buffer_unreachable';
        }
        if ($flushAge === null || $flushAge > $staleAfter) {
            $problems[] = 'collector_stale';
        }
        if ($backlog !== null && $backlog >= $cap * 0.8) {
            $problems[] = 'buffer_near_cap';
        }
        if (StreamSink::$uncountedLoss > 0) {
            $problems[] = 'uncounted_loss';
        }

        return [
            'enabled' => (bool) config('esi_diagnostics.enabled'),
            'healthy' => $problems === [],
            'problems' => $problems,
            'last_flushed_at' => $lastFlushed,
            'seconds_since_flush' => $flushAge,
            'backlog' => $backlog,
            'oldest_event_age_seconds' => $oldestAge,
            'dropped_events' => (int) ($checkpoint->dropped_events ?? 0) + ($bufferError === null ? $this->stream->pendingDrops() : 0),
            'gap_count' => (int) ($checkpoint->gap_count ?? 0),
            'last_gap_at' => $checkpoint->last_gap_at ?? null,
            'last_gap_reason' => $checkpoint->last_gap_reason ?? null,
            'expired_rows' => (int) ($checkpoint->expired_rows ?? 0),
        ];
    }
}
