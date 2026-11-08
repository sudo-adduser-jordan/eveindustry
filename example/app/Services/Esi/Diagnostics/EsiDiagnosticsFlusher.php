<?php

namespace App\Services\Esi\Diagnostics;

use App\Services\Esi\Diagnostics\Streams\EsiDiagnosticsStream;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Drains the stream into MySQL. One batch is one SQL transaction that holds the
 * checkpoint row: failure details, aggregates and the new checkpoint commit
 * together, and the stream is trimmed only afterwards. A crash before commit
 * replays the batch; a crash after commit finds it at or below the checkpoint.
 */
class EsiDiagnosticsFlusher
{
    public const CHECKPOINT = 'collector';

    private const UPSTREAM_KINDS = ['http_attempt', 'connection_failure'];

    private const COUNTED_KINDS = ['http_attempt', 'connection_failure', 'local_deferral', 'cache_hit', 'cache_miss', 'cache_revalidation'];

    public function __construct(protected EsiDiagnosticsStream $stream) {}

    /** @return array{locked: bool, events: int, batches: int, backlog: int} */
    public function run(?int $maxSeconds = null): array
    {
        $deadline = microtime(true) + ($maxSeconds ?? (int) config('esi_diagnostics.flush.max_seconds', 45));
        $lock = Cache::store(config('esi.coordination_store'))->lock('esi.diagnostics.flusher', 120);
        if (! $lock->get()) {
            return ['locked' => true, 'events' => 0, 'batches' => 0, 'backlog' => $this->backlog()];
        }
        try {
            $events = $batches = 0;
            do {
                $consumed = $this->flushBatch();
                $events += $consumed;
                $batches++;
            } while ($consumed > 0 && microtime(true) < $deadline);
            // A successful run, even an empty one, is the collector heartbeat.
            DB::table('esi_telemetry_checkpoints')->where('name', self::CHECKPOINT)->update(['last_flushed_at' => $this->now(), 'updated_at' => now()]);

            return ['locked' => false, 'events' => $events, 'batches' => $batches, 'backlog' => $this->backlog()];
        } finally {
            $lock->release();
        }
    }

    /** Consume one ordered batch. Returns the number of stream entries consumed. */
    public function flushBatch(): int
    {
        $size = max(1, (int) config('esi_diagnostics.flush.batch', 200));
        $result = DB::transaction(function () use ($size) {
            DB::table('esi_telemetry_checkpoints')->insertOrIgnore(['name' => self::CHECKPOINT, 'updated_at' => now()]);
            $checkpoint = DB::table('esi_telemetry_checkpoints')->where('name', self::CHECKPOINT)->lockForUpdate()->first();

            $epoch = $this->stream->epoch();
            $after = $checkpoint->last_stream_id;
            $consumed = (int) $checkpoint->entries_consumed;
            $lost = 0;
            $gap = null;
            if ($checkpoint->stream_epoch !== null && $checkpoint->stream_epoch !== $epoch) {
                // The buffer was flushed or replaced: unread entries are gone and ids restart.
                $gap = 'stream_reset';
                $after = null;
                $consumed = 0;
            }
            // Entries removed from the buffer = ever added - still present. Any beyond what this collector
            // consumed were evicted unread by the cap. (The trim after commit can lag, never lead.)
            $added = $this->stream->totalAdded();
            if ($added !== null) {
                $lost = max(0, $added - $this->stream->length() - $consumed);
                if ($lost > 0) {
                    $gap ??= 'trimmed_unread';
                    $consumed += $lost; // accounted for, so the gap is recorded once
                }
            }

            $entries = $this->stream->read($after, $size);
            $drops = $this->stream->pendingDrops();
            $decoded = [];
            $invalid = 0;
            $lastId = $after;
            foreach ($entries as $id => $json) {
                if ($lastId !== null && StreamId::compare($id, $lastId) <= 0) {
                    continue; // at or below the checkpoint: already committed
                }
                $lastId = $id;
                $consumed++;
                $event = json_decode($json, true);
                if (! is_array($event) || ! $this->valid($event)) {
                    $invalid++;

                    continue;
                }
                $decoded[] = $event;
            }

            $this->apply($decoded);

            $update = [
                'stream_epoch' => $epoch,
                'last_stream_id' => $lastId,
                'entries_consumed' => $consumed,
                'dropped_events' => $checkpoint->dropped_events + $drops + $invalid + $lost,
                'updated_at' => now(),
            ];
            if ($gap !== null) {
                $update += ['gap_count' => $checkpoint->gap_count + 1, 'last_gap_at' => $this->now(), 'last_gap_reason' => $gap];
            }
            DB::table('esi_telemetry_checkpoints')->where('name', self::CHECKPOINT)->update($update);

            return [count($entries), $lastId, $drops];
        });

        [$count, $lastId, $drops] = $result;
        // Acknowledgement follows commit. A failure here only leaves entries that replay ignores.
        try {
            if ($lastId !== null) {
                $this->stream->trimThrough($lastId);
            }
            $this->stream->acknowledgeDrops($drops);
        } catch (\Throwable $e) {
        }

        return $count;
    }

    public function backlog(): int
    {
        try {
            return $this->stream->length();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected function valid(array $event): bool
    {
        return is_string($event['event_id'] ?? null) && is_string($event['kind'] ?? null)
            && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(\.\d{1,6})?$/', (string) ($event['occurred_at'] ?? '')) === 1;
    }

    /** Persist details and aggregates for already-validated events. Runs inside the batch transaction. */
    protected function apply(array $events): void
    {
        $details = [];
        $endpoint = [];
        $budget = [];
        foreach ($events as $event) {
            $kind = $event['kind'];
            $status = isset($event['http_status']) ? (int) $event['http_status'] : null;
            $failure = in_array($kind, ['connection_failure', 'circuit_opened', 'circuit_closed'], true)
                || ($kind === 'http_attempt' && $status !== null && $status >= 400);
            if ($failure) {
                $details[] = $this->detailRow($event);
            }
            if (in_array($kind, self::COUNTED_KINDS, true)) {
                $this->addEndpoint($endpoint, $event, $status);
            }
            $this->addBudget($budget, $event);
        }
        foreach (array_chunk($details, 25) as $chunk) {
            DB::table('esi_diagnostic_events')->insertOrIgnore($chunk);
        }
        foreach ($endpoint as $row) {
            $this->mergeEndpoint($row);
        }
        foreach ($budget as $row) {
            $this->mergeBudget($row);
        }
    }

    private function detailRow(array $event): array
    {
        $row = [];
        foreach (EsiDiagnosticEvent::columns() as $column) {
            $row[$column] = $event[$column] ?? null;
        }
        $row['operation_key'] ??= '';
        $row['source_identity'] ??= '';
        $row['application_id'] ??= '';
        $row['reason'] ??= 'unknown';
        $row['response_received'] = (int) (bool) ($row['response_received'] ?? false);

        return $row;
    }

    private function addEndpoint(array &$rows, array $event, ?int $status): void
    {
        $kind = $event['kind'];
        $bucket = substr($event['occurred_at'], 0, 13).':00:00';
        $dims = [
            'operation_key' => (string) ($event['operation_key'] ?? ''), 'event_kind' => $kind, 'http_status' => $status ?? 0,
            'reason' => (string) ($event['reason'] ?? 'unknown'), 'dataset' => (string) ($event['dataset'] ?? ''),
            'source_identity' => (string) ($event['source_identity'] ?? ''), 'limiter_group' => (string) ($event['limiter_group'] ?? ''),
        ];
        $hash = hash('sha256', json_encode([$bucket, array_values($dims)]));
        $upstream = in_array($kind, self::UPSTREAM_KINDS, true);
        $key = $hash.'|'.$bucket;
        $rows[$key] ??= $dims + [
            'dimension_hash' => $hash, 'bucket_at' => $bucket, 'request_count' => 0, 'success_count' => 0, 'error_count' => 0,
            'duration_sum_ms' => 0, 'duration_count' => 0, 'duration_max_ms' => 0, 'first_observed_at' => $event['occurred_at'], 'last_observed_at' => $event['occurred_at'],
        ];
        $row = &$rows[$key];
        $row['request_count']++;
        if ($upstream && $status !== null && ($status < 300 || $status === 304)) {
            $row['success_count']++;
        }
        if ($upstream && ($status === null || $status >= 400)) {
            $row['error_count']++;
        }
        if (isset($event['duration_ms'])) {
            $row['duration_sum_ms'] += (int) $event['duration_ms'];
            $row['duration_count']++;
            $row['duration_max_ms'] = max($row['duration_max_ms'], (int) $event['duration_ms']);
        }
        $row['first_observed_at'] = min($row['first_observed_at'], $event['occurred_at']);
        $row['last_observed_at'] = max($row['last_observed_at'], $event['occurred_at']);
    }

    private function mergeEndpoint(array $row): void
    {
        $existing = DB::table('esi_endpoint_metrics')->where('dimension_hash', $row['dimension_hash'])->where('bucket_at', $row['bucket_at'])->lockForUpdate()->first();
        if ($existing === null) {
            DB::table('esi_endpoint_metrics')->insert($row);

            return;
        }
        DB::table('esi_endpoint_metrics')->where('id', $existing->id)->update([
            'request_count' => $existing->request_count + $row['request_count'],
            'success_count' => $existing->success_count + $row['success_count'],
            'error_count' => $existing->error_count + $row['error_count'],
            'duration_sum_ms' => $existing->duration_sum_ms + $row['duration_sum_ms'],
            'duration_count' => $existing->duration_count + $row['duration_count'],
            'duration_max_ms' => max($existing->duration_max_ms, $row['duration_max_ms']),
            'first_observed_at' => min((string) $existing->first_observed_at, $row['first_observed_at']),
            'last_observed_at' => max((string) $existing->last_observed_at, $row['last_observed_at']),
        ]);
    }

    private function addBudget(array &$rows, array $event): void
    {
        $kind = $event['kind'];
        $observations = [];
        if ($kind === 'http_attempt') {
            if (isset($event['legacy_remaining'])) {
                $observations[] = ['legacy', '', (int) $event['legacy_remaining'], null, $event['legacy_reset_at'] ?? null, 0, 0, 0];
            }
            if (isset($event['token_remaining']) && ! empty($event['limiter_group'])) {
                $observations[] = ['token', (string) $event['limiter_group'], (int) $event['token_remaining'], isset($event['token_used']) ? (int) $event['token_used'] : null, null, 0, 0, 0];
            }
        } elseif ($kind === 'circuit_opened') {
            $observations[] = ['legacy', '', null, null, $event['legacy_reset_at'] ?? null, 1, 0, 0];
        } elseif ($kind === 'circuit_closed') {
            $observations[] = ['legacy', '', null, null, null, 0, 1, (int) round(((int) ($event['duration_ms'] ?? 0)) / 1000)];
        }
        foreach ($observations as [$mechanism, $group, $remaining, $used, $resetAt, $opened, $closed, $pause]) {
            foreach (['minute' => 16, 'hour' => 13] as $granularity => $length) {
                $bucket = substr($event['occurred_at'], 0, $length).($granularity === 'minute' ? ':00' : ':00:00');
                $source = (string) ($event['source_identity'] ?? '');
                $application = (string) ($event['application_id'] ?? '');
                $hash = hash('sha256', json_encode([$granularity, $bucket, $source, $application, $mechanism, $group]));
                $key = $hash.'|'.$bucket;
                $rows[$key] ??= [
                    'dimension_hash' => $hash, 'bucket_at' => $bucket, 'granularity' => $granularity, 'source_identity' => $source,
                    'application_id' => $application, 'mechanism' => $mechanism, 'limiter_group' => $group, 'observations' => 0,
                    'remaining_min' => null, 'remaining_max' => null, 'remaining_first' => null, 'remaining_last' => null,
                    'used_sum' => null, 'reset_at_min' => null, 'reset_at_max' => null,
                    'circuit_opened_count' => 0, 'circuit_closed_count' => 0, 'pause_seconds' => 0,
                ];
                $row = &$rows[$key];
                if ($remaining !== null) {
                    $row['observations']++;
                    $row['remaining_min'] = $row['remaining_min'] === null ? $remaining : min($row['remaining_min'], $remaining);
                    $row['remaining_max'] = $row['remaining_max'] === null ? $remaining : max($row['remaining_max'], $remaining);
                    $row['remaining_first'] ??= $remaining;
                    $row['remaining_last'] = $remaining;
                }
                if ($used !== null) {
                    $row['used_sum'] = ($row['used_sum'] ?? 0) + $used;
                }
                if ($resetAt !== null) {
                    $row['reset_at_min'] = $row['reset_at_min'] === null ? $resetAt : min($row['reset_at_min'], $resetAt);
                    $row['reset_at_max'] = $row['reset_at_max'] === null ? $resetAt : max($row['reset_at_max'], $resetAt);
                }
                $row['circuit_opened_count'] += $opened;
                $row['circuit_closed_count'] += $closed;
                $row['pause_seconds'] += $pause;
                unset($row);
            }
        }
    }

    private function mergeBudget(array $row): void
    {
        $existing = DB::table('esi_budget_metrics')->where('dimension_hash', $row['dimension_hash'])->where('bucket_at', $row['bucket_at'])->lockForUpdate()->first();
        if ($existing === null) {
            DB::table('esi_budget_metrics')->insert($row);

            return;
        }
        $pick = fn ($a, $b, $fn) => $a === null ? $b : ($b === null ? $a : $fn($a, $b));
        DB::table('esi_budget_metrics')->where('id', $existing->id)->update([
            'observations' => $existing->observations + $row['observations'],
            'remaining_min' => $pick($existing->remaining_min, $row['remaining_min'], 'min'),
            'remaining_max' => $pick($existing->remaining_max, $row['remaining_max'], 'max'),
            'remaining_first' => $existing->remaining_first ?? $row['remaining_first'],
            'remaining_last' => $row['remaining_last'] ?? $existing->remaining_last,
            'used_sum' => $existing->used_sum === null && $row['used_sum'] === null ? null : ($existing->used_sum ?? 0) + ($row['used_sum'] ?? 0),
            'reset_at_min' => $pick($existing->reset_at_min, $row['reset_at_min'], 'min'),
            'reset_at_max' => $pick($existing->reset_at_max, $row['reset_at_max'], 'max'),
            'circuit_opened_count' => $existing->circuit_opened_count + $row['circuit_opened_count'],
            'circuit_closed_count' => $existing->circuit_closed_count + $row['circuit_closed_count'],
            'pause_seconds' => $existing->pause_seconds + $row['pause_seconds'],
        ]);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
