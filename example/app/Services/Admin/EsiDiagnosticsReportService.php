<?php

namespace App\Services\Admin;

use App\Models\Character;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only reports over the ESI diagnostics tables. Rates use real upstream
 * attempts (http_attempt + connection_failure) as the denominator; cache hits
 * and local deferrals are separate series. Endpoint metrics are hourly, so a
 * range edge inside an hour includes that whole hour.
 */
class EsiDiagnosticsReportService
{
    private const UPSTREAM = ['http_attempt', 'connection_failure'];

    private const ERROR_STATUSES = [401, 403, 404, 420, 429];

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $upstream = $this->endpointQuery($from, $to, $filters)->whereIn('event_kind', self::UPSTREAM);
        $requests = (int) (clone $upstream)->sum('request_count');
        $errors = (int) (clone $upstream)->sum('error_count');
        $byStatus = (clone $upstream)->where(fn ($q) => $q->where('http_status', 0)->orWhere('http_status', '>=', 400))
            ->groupBy('http_status')->selectRaw('http_status, SUM(request_count) as total')->pluck('total', 'http_status')->map(fn ($v) => (int) $v)->all();
        $other = $errors - array_sum(array_intersect_key($byStatus, array_flip(array_merge(self::ERROR_STATUSES, [0]))));
        $kinds = $this->endpointQuery($from, $to, $filters)->whereIn('event_kind', ['local_deferral', 'cache_hit', 'cache_miss', 'cache_revalidation'])
            ->groupBy('event_kind')->selectRaw('event_kind, SUM(request_count) as total')->pluck('total', 'event_kind')->map(fn ($v) => (int) $v)->all();

        $budget = DB::table('esi_budget_metrics')->where('granularity', 'hour')->where('bucket_at', '>=', $this->hour($from))->where('bucket_at', '<=', $this->fmt($to));
        $legacy = (clone $budget)->where('mechanism', 'legacy');
        $releases = DB::table('esi_diagnostic_events')->whereNotNull('release')->whereBetween('occurred_at', [$this->fmt($from), $this->fmt($to)])
            ->groupBy('release')->select('release')->selectRaw('MIN(occurred_at) as first_seen')->orderBy('first_seen')->get();

        return [
            'from' => $this->iso($this->fmt($from)),
            'to' => $this->iso($this->fmt($to)),
            'upstream_requests' => $requests,
            'errors' => $errors,
            'error_percent' => $requests > 0 ? round($errors * 100 / $requests, 2) : null,
            'status_401' => $byStatus[401] ?? 0,
            'status_403' => $byStatus[403] ?? 0,
            'status_404' => $byStatus[404] ?? 0,
            'throttled_420' => $byStatus[420] ?? 0,
            'throttled_429' => $byStatus[429] ?? 0,
            'other_errors' => max(0, $other),
            'connection_failures' => $byStatus[0] ?? 0,
            'local_deferrals' => $kinds['local_deferral'] ?? 0,
            'cache_hits' => $kinds['cache_hit'] ?? 0,
            'cache_misses' => $kinds['cache_miss'] ?? 0,
            'cache_revalidations' => $kinds['cache_revalidation'] ?? 0,
            'protection_episodes' => (int) (clone $legacy)->sum('circuit_opened_count'),
            'observed_pause_seconds' => (int) (clone $legacy)->sum('pause_seconds'),
            'min_legacy_remaining' => (clone $legacy)->min('remaining_min') === null ? null : (int) (clone $legacy)->min('remaining_min'),
            'releases' => $releases->map(fn ($r) => ['release' => $r->release, 'first_seen' => $this->iso($r->first_seen)])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function trends(CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $rows = $this->endpointQuery($from, $to, $filters)->whereIn('event_kind', self::UPSTREAM)->groupBy('bucket_at')->orderBy('bucket_at')
            ->selectRaw('bucket_at, SUM(request_count) as requests, SUM(error_count) as errors,'
                .' SUM(CASE WHEN http_status = 401 THEN request_count ELSE 0 END) as s401,'
                .' SUM(CASE WHEN http_status = 403 THEN request_count ELSE 0 END) as s403,'
                .' SUM(CASE WHEN http_status = 404 THEN request_count ELSE 0 END) as s404,'
                .' SUM(CASE WHEN http_status = 420 THEN request_count ELSE 0 END) as s420,'
                .' SUM(CASE WHEN http_status = 429 THEN request_count ELSE 0 END) as s429')->get();
        $deferrals = $this->endpointQuery($from, $to, $filters)->where('event_kind', 'local_deferral')->groupBy('bucket_at')
            ->selectRaw('bucket_at, SUM(request_count) as total')->pluck('total', 'bucket_at');
        $cacheHits = $this->endpointQuery($from, $to, $filters)->where('event_kind', 'cache_hit')->groupBy('bucket_at')
            ->selectRaw('bucket_at, SUM(request_count) as total')->pluck('total', 'bucket_at');

        $granularity = $from->diffInSeconds($to) <= 6 * 3600 ? 'minute' : 'hour';
        $budget = DB::table('esi_budget_metrics')->where('granularity', $granularity)
            ->where('bucket_at', '>=', $granularity === 'hour' ? $this->hour($from) : $this->fmt($from))->where('bucket_at', '<=', $this->fmt($to));
        if (isset($filters['group'])) {
            $budget->where('limiter_group', $filters['group']);
        }
        $legacy = (clone $budget)->where('mechanism', 'legacy')->groupBy('bucket_at')->orderBy('bucket_at')
            ->selectRaw('bucket_at, MIN(remaining_min) as remaining_min, SUM(circuit_opened_count) as opened')->get();
        $tokens = (clone $budget)->where('mechanism', 'token')->orderBy('bucket_at')->get(['bucket_at', 'limiter_group', 'remaining_min', 'used_sum']);

        return [
            'granularity' => $granularity,
            'buckets' => $rows->map(fn ($r) => [
                'at' => $this->iso($r->bucket_at), 'requests' => (int) $r->requests, 'errors' => (int) $r->errors,
                'status_401' => (int) $r->s401, 'status_403' => (int) $r->s403, 'status_404' => (int) $r->s404,
                'throttled_420' => (int) $r->s420, 'throttled_429' => (int) $r->s429,
                'local_deferrals' => (int) ($deferrals[$r->bucket_at] ?? 0), 'cache_hits' => (int) ($cacheHits[$r->bucket_at] ?? 0),
            ])->all(),
            'legacy_budget' => $legacy->map(fn ($r) => [
                'at' => $this->iso($r->bucket_at), 'remaining_min' => $r->remaining_min === null ? null : (int) $r->remaining_min, 'protection_episodes' => (int) $r->opened,
            ])->all(),
            'token_groups' => $tokens->groupBy('limiter_group')->map(fn ($group, $name) => [
                'group' => (string) $name,
                'points' => $group->map(fn ($r) => ['at' => $this->iso($r->bucket_at), 'remaining_min' => $r->remaining_min === null ? null : (int) $r->remaining_min, 'used' => $r->used_sum === null ? null : (int) $r->used_sum])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function endpoints(CarbonImmutable $from, CarbonImmutable $to, array $filters, int $limit = 25): array
    {
        $upstream = fn () => $this->endpointQuery($from, $to, $filters)->whereIn('event_kind', self::UPSTREAM);
        $totalRequests = (int) $upstream()->sum('request_count');
        $totalErrors = (int) $upstream()->sum('error_count');

        $operations = $upstream()->groupBy('operation_key')->orderByDesc('errors')->orderByDesc('requests')->limit($limit)
            ->selectRaw('operation_key, SUM(request_count) as requests, SUM(error_count) as errors')->get()
            ->map(fn ($r) => [
                'operation' => $r->operation_key, 'requests' => (int) $r->requests, 'errors' => (int) $r->errors,
                'errors_per_1000' => $r->requests > 0 ? round($r->errors * 1000 / $r->requests, 1) : null,
            ])->all();

        $reasons = $upstream()->where(fn ($q) => $q->where('http_status', 0)->orWhere('http_status', '>=', 400))
            ->groupBy('operation_key', 'http_status', 'reason', 'dataset')->orderByDesc('errors')->limit($limit)
            ->selectRaw('operation_key, http_status, reason, dataset, SUM(request_count) as errors')->get()
            ->map(fn ($r) => [
                'operation' => $r->operation_key, 'http_status' => $r->http_status ? (int) $r->http_status : null, 'reason' => $r->reason,
                'dataset' => $r->dataset === '' ? null : $r->dataset, 'errors' => (int) $r->errors,
                'share_of_errors' => $totalErrors > 0 ? round($r->errors * 100 / $totalErrors, 1) : null,
                'errors_per_1000_requests' => $totalRequests > 0 ? round($r->errors * 1000 / $totalRequests, 1) : null,
            ])->all();

        return ['total_requests' => $totalRequests, 'total_errors' => $totalErrors, 'operations' => $operations, 'reasons' => $reasons];
    }

    /**
     * Failure detail, newest first, cursor-paged by id.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, next_cursor: int|null}
     */
    public function events(CarbonImmutable $from, CarbonImmutable $to, array $filters, ?int $beforeId, int $perPage): array
    {
        $query = DB::table('esi_diagnostic_events')->whereBetween('occurred_at', [$this->fmt($from), $this->fmt($to)]);
        foreach (['operation' => 'operation_key', 'dataset' => 'dataset', 'status' => 'http_status', 'reason' => 'reason', 'group' => 'limiter_group', 'character_id' => 'character_id', 'corporation_id' => 'corporation_id'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }
        $rows = $query->orderByDesc('id')->limit($perPage + 1)->get();
        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage);
        $characters = Character::whereIn('character_id', $rows->pluck('character_id')->filter()->unique()->all())->get(['character_id', 'name', 'user_id'])->keyBy('character_id');

        return [
            'rows' => $rows->map(function ($r) use ($characters) {
                $character = $r->character_id ? $characters->get($r->character_id) : null;

                return [
                    'id' => (int) $r->id, 'occurred_at' => $this->iso($r->occurred_at), 'kind' => $r->kind, 'operation' => $r->operation_key === '' ? null : $r->operation_key,
                    'http_status' => $r->http_status === null ? null : (int) $r->http_status, 'reason' => $r->reason, 'dataset' => $r->dataset, 'job_class' => $r->job_class,
                    'correlation_id' => $r->correlation_id, 'character_id' => $r->character_id === null ? null : (int) $r->character_id,
                    'character_name' => $character?->name, 'user_id' => $character?->user_id === null ? null : (int) $character->user_id,
                    'corporation_id' => $r->corporation_id === null ? null : (int) $r->corporation_id, 'resource_id' => $r->resource_id === null ? null : (int) $r->resource_id,
                    'page' => $r->page, 'batch_size' => $r->batch_size, 'duration_ms' => $r->duration_ms, 'limiter_group' => $r->limiter_group,
                    'legacy_remaining' => $r->legacy_remaining, 'retry_after_seconds' => $r->retry_after_seconds, 'upstream_request_id' => $r->upstream_request_id,
                    'batch_fingerprint' => $r->batch_fingerprint, 'release' => $r->release,
                ];
            })->values()->all(),
            'next_cursor' => $more ? (int) $rows->last()->id : null,
        ];
    }

    /**
     * Repeated batch fingerprints: a recurring batch, not which item in it is invalid.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function batches(CarbonImmutable $from, CarbonImmutable $to, array $filters, int $limit = 25): array
    {
        $query = DB::table('esi_diagnostic_events')->whereNotNull('batch_fingerprint')->whereBetween('occurred_at', [$this->fmt($from), $this->fmt($to)]);
        foreach (['operation' => 'operation_key', 'dataset' => 'dataset', 'status' => 'http_status', 'reason' => 'reason', 'character_id' => 'character_id', 'corporation_id' => 'corporation_id'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        return $query->groupBy('batch_fingerprint', 'operation_key', 'dataset', 'reason')->havingRaw('COUNT(*) > 1')->orderByDesc('occurrences')->limit($limit)
            ->selectRaw('batch_fingerprint, operation_key, dataset, reason, COUNT(*) as occurrences, COUNT(DISTINCT character_id) as characters, MIN(batch_size) as batch_size, MIN(occurred_at) as first_seen, MAX(occurred_at) as last_seen, MIN(character_id) as character_id')
            ->get()->map(fn ($r) => [
                'fingerprint' => $r->batch_fingerprint, 'operation' => $r->operation_key, 'dataset' => $r->dataset, 'reason' => $r->reason,
                'occurrences' => (int) $r->occurrences, 'characters' => (int) $r->characters, 'batch_size' => $r->batch_size === null ? null : (int) $r->batch_size,
                'first_seen' => $this->iso($r->first_seen), 'last_seen' => $this->iso($r->last_seen),
                'character_id' => $r->character_id === null ? null : (int) $r->character_id,
            ])->all();
    }

    /** @param  array<string, mixed>  $filters */
    private function endpointQuery(CarbonImmutable $from, CarbonImmutable $to, array $filters): Builder
    {
        $query = DB::table('esi_endpoint_metrics')->where('bucket_at', '>=', $this->hour($from))->where('bucket_at', '<=', $this->fmt($to));
        foreach (['operation' => 'operation_key', 'dataset' => 'dataset', 'status' => 'http_status', 'reason' => 'reason', 'group' => 'limiter_group'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        return $query;
    }

    private function fmt(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d H:i:s');
    }

    private function hour(CarbonImmutable $time): string
    {
        return $time->utc()->startOfHour()->format('Y-m-d H:i:s');
    }

    private function iso(?string $stored): ?string
    {
        return $stored === null ? null : CarbonImmutable::parse($stored, 'UTC')->toIso8601ZuluString();
    }
}
