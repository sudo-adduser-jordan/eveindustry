<?php

namespace App\Services\Esi\Diagnostics;

use Illuminate\Support\Facades\DB;

/** Deletes expired diagnostics in small indexed batches. */
class EsiDiagnosticsRetention
{
    /** @return array<string, int> rows deleted per table/series */
    public function prune(?int $maxSeconds = null): array
    {
        $config = config('esi_diagnostics.retention');
        $deadline = microtime(true) + ($maxSeconds ?? 120);
        $batch = max(1, (int) $config['prune_batch']);
        $plan = [
            'events' => ['esi_diagnostic_events', 'occurred_at', now()->subDays($config['events_days']), null],
            'endpoint_metrics' => ['esi_endpoint_metrics', 'bucket_at', now()->subDays($config['endpoint_metrics_days']), null],
            'budget_minute' => ['esi_budget_metrics', 'bucket_at', now()->subDays($config['budget_minute_days']), 'minute'],
            'budget_hour' => ['esi_budget_metrics', 'bucket_at', now()->subDays($config['budget_hour_days']), 'hour'],
        ];
        $deleted = [];
        foreach ($plan as $name => [$table, $column, $cutoff, $granularity]) {
            $deleted[$name] = 0;
            do {
                $query = DB::table($table)->where($column, '<', $cutoff->utc()->format('Y-m-d H:i:s'));
                if ($granularity !== null) {
                    $query->where('granularity', $granularity);
                }
                $ids = $query->orderBy($column)->limit($batch)->pluck('id')->all();
                if ($ids !== []) {
                    $deleted[$name] += DB::table($table)->whereIn('id', $ids)->delete();
                }
            } while (count($ids) === $batch && microtime(true) < $deadline);
        }
        $total = array_sum($deleted);
        if ($total > 0) {
            // Deliberate expiry is tracked apart from captures lost before SQL.
            DB::table('esi_telemetry_checkpoints')->insertOrIgnore(['name' => EsiDiagnosticsFlusher::CHECKPOINT, 'updated_at' => now()]);
            DB::table('esi_telemetry_checkpoints')->where('name', EsiDiagnosticsFlusher::CHECKPOINT)->increment('expired_rows', $total);
        }

        return $deleted;
    }
}
