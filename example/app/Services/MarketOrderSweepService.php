<?php

namespace App\Services;

use App\Jobs\PrecomputeManufacturingCostsChunk;
use App\Models\EveType;
use App\Models\MarketOrder;
use App\Models\MarketOrderSweep;
use App\Models\MarketRegionState;
use App\Services\Esi\RegionOrdersPage;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Bookkeeping for region order sweeps (market:fetch-region-orders).
 *
 * A sweep fetches every ESI page of a region's orders. Each stored or
 * unchanged page stamps its orders with updated_at = now, so once every page
 * has arrived the orders the sweep did not see are exactly those with
 * updated_at before the sweep started; only then are they deleted and the
 * region's market_aggregates rebuilt. A sweep with any failed page deletes
 * nothing.
 *
 * Also owns the per-page ETag cache and the Jita cadence gate.
 */
class MarketOrderSweepService
{
    /** Jita runs every 15 min only while its last sweep took less than this. */
    public const FAST_CADENCE_MAX_SECONDS = 600;

    public const FAST_CADENCE_MINUTES = 15;

    public const SLOW_CADENCE_MINUTES = 30;

    /** A sweep still "running" after this long is treated as abandoned. */
    public const RUNNING_TIMEOUT_MINUTES = 30;

    /** Per-page ETag + order ids; long enough to span the hourly hub sweeps. */
    public const PAGE_CACHE_TTL = 7200;

    public const PAGE_CACHE_PREFIX = 'market.orders.page.';

    /** A completed sweep starts precompute at most this often per region. */
    public const PRECOMPUTE_MIN_INTERVAL_MINUTES = 30;

    public const PRECOMPUTE_TRIGGER_PREFIX = 'market.precompute.triggered.';

    /** Rows per order upsert / delete. */
    public const WRITE_CHUNK = 500;

    public const DELETE_CHUNK = 5000;

    public function __construct(
        protected MarketAggregateService $aggregates,
    ) {}

    /**
     * Start a sweep for the region, or return null when one is already
     * running. A running sweep older than RUNNING_TIMEOUT_MINUTES is marked
     * abandoned so a lost batch cannot block the region forever.
     */
    public function start(int $regionId, ?CarbonInterface $startedAt = null): ?MarketOrderSweep
    {
        $startedAt ??= now();

        $this->ensureRegionState($regionId);

        return DB::transaction(function () use ($regionId, $startedAt) {
            $state = MarketRegionState::where('region_id', $regionId)->lockForUpdate()->firstOrFail();

            MarketOrderSweep::where('region_id', $regionId)
                ->whereIn('status', [MarketOrderSweep::STATUS_RUNNING, MarketOrderSweep::STATUS_COMPLETING])
                ->where('started_at', '<', now()->subMinutes(self::RUNNING_TIMEOUT_MINUTES))
                ->update([
                    'status' => MarketOrderSweep::STATUS_ABANDONED,
                    'finished_at' => now(),
                    'error' => 'Did not finish within '.self::RUNNING_TIMEOUT_MINUTES.' minutes',
                ]);

            $running = MarketOrderSweep::where('region_id', $regionId)
                ->whereIn('status', [MarketOrderSweep::STATUS_RUNNING, MarketOrderSweep::STATUS_COMPLETING])
                ->lockForUpdate()
                ->first(['id']);

            if ($running !== null) {
                return null;
            }

            $generation = $state->latest_generation + 1;
            $sweep = MarketOrderSweep::create([
                'sweep_id' => (string) Str::uuid(),
                'region_id' => $regionId,
                'generation' => $generation,
                'status' => MarketOrderSweep::STATUS_RUNNING,
                'started_at' => $startedAt,
            ]);

            $state->update([
                'latest_generation' => $generation,
                'latest_sweep_id' => $sweep->sweep_id,
                'latest_started_at' => $startedAt,
            ]);

            return $sweep;
        });
    }

    public function setPagesTotal(string $sweepId, int $pages): void
    {
        $this->retryOnDeadlock(fn () => DB::transaction(function () use ($sweepId, $pages): void {
            $sweep = MarketOrderSweep::where('sweep_id', $sweepId)->first(['region_id']);

            $current = $sweep === null ? null : $this->lockCurrentRunningSweep($sweep->region_id, $sweepId);

            if ($current === null) {
                return;
            }

            $current->update(['pages_total' => $pages]);
        }, 1));
    }

    /**
     * The ETag and order ids cached for a page by the last sweep that stored
     * it, or null. Both live in one cache entry so they are evicted together:
     * an ETag is only ever sent when the ids needed to handle a 304 are known.
     *
     * @return array{etag: string, order_ids: list<int>, pages: int}|null
     */
    public function cachedPage(int $regionId, int $page): ?array
    {
        $entry = Cache::get(self::PAGE_CACHE_PREFIX."{$regionId}.{$page}");

        return is_array($entry) && isset($entry['etag'], $entry['order_ids']) ? $entry : null;
    }

    /**
     * Upsert one fetched page and remember its ETag.
     *
     * @return int orders stored
     */
    public function storePage(string $sweepId, int $regionId, int $page, RegionOrdersPage $result): int
    {
        $now = now();
        $rows = [];

        foreach ($result->orders as $order) {
            $orderId = $order['order_id'] ?? null;
            if (! $orderId) {
                continue;
            }

            $rows[] = [
                'order_id' => $orderId,
                'region_id' => $regionId,
                'type_id' => $order['type_id'] ?? 0,
                'is_buy_order' => (bool) ($order['is_buy_order'] ?? false),
                'price' => (float) ($order['price'] ?? 0),
                'volume_remain' => (int) ($order['volume_remain'] ?? 0),
                'volume_total' => (int) ($order['volume_total'] ?? 0),
                'min_volume' => (int) ($order['min_volume'] ?? 1),
                'location_id' => $order['location_id'] ?? null,
                'duration' => $order['duration'] ?? null,
                'range' => $order['range'] ?? null,
                'issued' => isset($order['issued']) ? Carbon::parse($order['issued'])->toDateTimeString() : null,
                'batch_id' => $sweepId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $accepted = $this->retryOnDeadlock(fn () => DB::transaction(function () use ($sweepId, $regionId, $rows): bool {
            if ($this->lockCurrentRunningSweep($regionId, $sweepId) === null) {
                return false;
            }

            foreach (array_chunk($rows, self::WRITE_CHUNK) as $chunk) {
                MarketOrder::upsert($chunk, ['order_id'], [
                    'region_id', 'type_id', 'is_buy_order', 'price',
                    'volume_remain', 'volume_total', 'min_volume',
                    'location_id', 'duration', 'range', 'issued',
                    'batch_id', 'updated_at',
                ]);
            }

            $this->increment($sweepId, ['pages_fetched' => 1, 'orders_seen' => count($rows)]);

            return true;
        }, 1));

        if (! $accepted) {
            return 0;
        }

        if ($result->etag !== null) {
            Cache::put(self::PAGE_CACHE_PREFIX."{$regionId}.{$page}", [
                'etag' => $result->etag,
                'order_ids' => array_map('intval', array_column($rows, 'order_id')),
                'pages' => $result->pages ?? 1,
            ], self::PAGE_CACHE_TTL);
        }

        return count($rows);
    }

    /**
     * ESI answered 304 for the cached ETag: the page holds the same orders as
     * last time, so mark them seen by this sweep without rewriting them.
     *
     * @param  array{etag: string, order_ids: list<int>, pages: int}  $cached
     * @return int orders marked seen
     */
    public function markPageUnchanged(string $sweepId, int $regionId, int $page, array $cached): int
    {
        $now = now();
        $ids = array_map('intval', $cached['order_ids']);

        $accepted = $this->retryOnDeadlock(fn () => DB::transaction(function () use ($sweepId, $regionId, $ids, $now): bool {
            if ($this->lockCurrentRunningSweep($regionId, $sweepId) === null) {
                return false;
            }

            foreach (array_chunk($ids, self::WRITE_CHUNK) as $chunk) {
                MarketOrder::where('region_id', $regionId)
                    ->whereIn('order_id', $chunk)
                    ->update(['batch_id' => $sweepId, 'updated_at' => $now]);
            }

            $this->increment($sweepId, ['pages_fetched' => 1, 'pages_not_modified' => 1, 'orders_seen' => count($ids)]);

            return true;
        }, 1));

        if (! $accepted) {
            return 0;
        }

        // Keep the entry alive for the next sweep.
        Cache::put(self::PAGE_CACHE_PREFIX."{$regionId}.{$page}", $cached, self::PAGE_CACHE_TTL);

        return count($ids);
    }

    /**
     * A page past the end (404): the region shrank between ESI snapshots.
     */
    public function markPageMissing(string $sweepId, int $regionId, int $page): void
    {
        $accepted = $this->retryOnDeadlock(fn () => DB::transaction(function () use ($sweepId, $regionId): bool {
            if ($this->lockCurrentRunningSweep($regionId, $sweepId) === null) {
                return false;
            }

            $this->increment($sweepId, ['pages_fetched' => 1]);

            return true;
        }, 1));

        if ($accepted) {
            Cache::forget(self::PAGE_CACHE_PREFIX."{$regionId}.{$page}");
        }
    }

    /**
     * Every page arrived: delete orders the sweep did not see, rebuild the
     * region's aggregates, publish the new snapshot and start precompute.
     */
    public function complete(string $sweepId): ?MarketOrderSweep
    {
        $sweep = MarketOrderSweep::where('sweep_id', $sweepId)->first();
        if ($sweep === null) {
            Log::warning("MarketOrderSweep: complete called for unknown sweep {$sweepId}");

            return null;
        }

        if ($sweep->status === MarketOrderSweep::STATUS_COMPLETED) {
            $this->aggregates->publishSnapshot($sweep->region_id, $sweepId, $sweep->started_at);

            return $sweep;
        }

        if ($sweep->status === MarketOrderSweep::STATUS_RUNNING
            && $sweep->pages_total > 0
            && $sweep->pages_fetched < $sweep->pages_total) {
            $this->fail($sweepId, 'Page receipt count is incomplete; retaining the active aggregate generation');

            return $sweep->fresh();
        }

        if (! $this->claimCompletion($sweep)) {
            Log::warning("MarketOrderSweep: sweep {$sweepId} is {$sweep->status}, not completing it");

            return $sweep->fresh();
        }

        $regionId = $sweep->region_id;
        $deleted = $this->deleteUnseenOrders($regionId, $sweep->started_at);
        $types = $this->aggregates->rebuild($regionId, $sweepId, $sweep->started_at);

        $finishedAt = now();
        $published = DB::transaction(function () use ($regionId, $sweepId, $finishedAt, $deleted, $types): bool {
            $state = MarketRegionState::where('region_id', $regionId)->lockForUpdate()->first();
            $freshSweep = MarketOrderSweep::where('sweep_id', $sweepId)->lockForUpdate()->first();

            if ($state === null || $freshSweep === null
                || $state->latest_sweep_id !== $sweepId
                || $state->latest_generation !== $freshSweep->generation
                || ! in_array($freshSweep->status, [MarketOrderSweep::STATUS_COMPLETING, MarketOrderSweep::STATUS_COMPLETED], true)) {
                return false;
            }

            if ($freshSweep->status === MarketOrderSweep::STATUS_COMPLETED) {
                return $state->active_sweep_id === $sweepId;
            }

            if ($freshSweep->generation <= $state->active_generation) {
                return $state->active_sweep_id === $sweepId;
            }

            $freshSweep->update([
                'status' => MarketOrderSweep::STATUS_COMPLETED,
                'finished_at' => $finishedAt,
                'duration_seconds' => max(0, (int) $freshSweep->started_at->diffInSeconds($finishedAt, true)),
                'orders_deleted' => $deleted,
                'types_aggregated' => $types,
            ]);

            $state->update([
                'active_generation' => $freshSweep->generation,
                'active_sweep_id' => $sweepId,
                'active_snapshot_at' => $freshSweep->started_at,
            ]);

            return true;
        });

        if (! $published) {
            Log::warning("MarketOrderSweep: stale sweep {$sweepId} generation rejected after aggregate build");

            return $sweep->fresh();
        }

        // The pointer already committed with completion; this call refreshes
        // its cache and remains idempotent for completion-job retries.
        $this->aggregates->publishSnapshot($regionId, $sweepId, $sweep->started_at);

        $sweep->refresh();

        Log::info("MarketOrderSweep: region {$regionId} sweep {$sweepId} completed in {$sweep->duration_seconds}s: {$sweep->pages_fetched} pages ({$sweep->pages_not_modified} unchanged), {$sweep->orders_seen} orders, {$deleted} removed");

        $this->triggerPrecompute($regionId);

        return $sweep;
    }

    /**
     * A page failed for good (or the batch was cancelled): keep every order,
     * record why. The next sweep starts afresh.
     */
    public function fail(string $sweepId, string $reason): void
    {
        $finishedAt = now();

        $sweep = DB::transaction(function () use ($sweepId, $reason, $finishedAt): ?MarketOrderSweep {
            $sweep = MarketOrderSweep::where('sweep_id', $sweepId)->lockForUpdate()->first();

            if ($sweep === null || ! in_array($sweep->status, [MarketOrderSweep::STATUS_RUNNING, MarketOrderSweep::STATUS_COMPLETING], true)) {
                return null;
            }

            $sweep->update([
                'status' => MarketOrderSweep::STATUS_FAILED,
                'finished_at' => $finishedAt,
                'duration_seconds' => max(0, (int) $sweep->started_at->diffInSeconds($finishedAt, true)),
                'error' => Str::limit($reason, 490),
            ]);

            return $sweep;
        });

        if ($sweep === null) {
            return;
        }

        Log::warning("MarketOrderSweep: region {$sweep->region_id} sweep {$sweepId} failed: {$reason}; no orders deleted");
    }

    public function lastCompleted(int $regionId): ?MarketOrderSweep
    {
        return MarketOrderSweep::where('region_id', $regionId)
            ->where('status', MarketOrderSweep::STATUS_COMPLETED)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();
    }

    public function latest(int $regionId): ?MarketOrderSweep
    {
        return MarketOrderSweep::where('region_id', $regionId)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Minutes between sweeps for a region on the fast (Jita) schedule:
     * 15 while the last completed sweep took under 10 minutes, else 30.
     * 30 until a sweep has completed and been measured.
     */
    public function cadenceMinutes(int $regionId): int
    {
        $last = $this->lastCompleted($regionId);

        if ($last === null || $last->duration_seconds === null) {
            return self::SLOW_CADENCE_MINUTES;
        }

        return $last->duration_seconds < self::FAST_CADENCE_MAX_SECONDS
            ? self::FAST_CADENCE_MINUTES
            : self::SLOW_CADENCE_MINUTES;
    }

    /**
     * Gate for the 15-minute Jita schedule entry. At a 30-minute cadence only
     * the :00 and :30 ticks run.
     */
    public function isDue(int $regionId, ?CarbonInterface $now = null): bool
    {
        $now ??= now();
        $cadence = $this->cadenceMinutes($regionId);

        return $now->minute % $cadence < self::FAST_CADENCE_MINUTES;
    }

    /**
     * Delete the region's orders not stamped by this sweep, in chunks so
     * one statement never locks the whole region.
     */
    protected function deleteUnseenOrders(int $regionId, CarbonInterface $startedAt): int
    {
        $deleted = 0;

        do {
            $batch = $this->retryOnDeadlock(fn () => MarketOrder::where('region_id', $regionId)
                ->where('updated_at', '<', $startedAt)
                ->limit(self::DELETE_CHUNK)
                ->delete());
            $deleted += $batch;
        } while ($batch >= self::DELETE_CHUNK);

        return $deleted;
    }

    private function ensureRegionState(int $regionId): void
    {
        $now = now();

        DB::table('market_region_states')->insertOrIgnore([
            'region_id' => $regionId,
            'latest_generation' => 0,
            'active_generation' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function lockCurrentRunningSweep(int $regionId, string $sweepId): ?MarketOrderSweep
    {
        $state = MarketRegionState::where('region_id', $regionId)->lockForUpdate()->first();
        $sweep = MarketOrderSweep::where('sweep_id', $sweepId)
            ->where('region_id', $regionId)
            ->where('status', MarketOrderSweep::STATUS_RUNNING)
            ->lockForUpdate()
            ->first();

        if ($state === null || $sweep === null
            || $state->latest_sweep_id !== $sweepId
            || $state->latest_generation !== $sweep->generation) {
            return null;
        }

        return $sweep;
    }

    private function claimCompletion(MarketOrderSweep $sweep): bool
    {
        return DB::transaction(function () use ($sweep): bool {
            $state = MarketRegionState::where('region_id', $sweep->region_id)->lockForUpdate()->first();
            $fresh = MarketOrderSweep::where('sweep_id', $sweep->sweep_id)->lockForUpdate()->first();

            if ($state === null || $fresh === null
                || $state->latest_sweep_id !== $fresh->sweep_id
                || $state->latest_generation !== $fresh->generation) {
                return false;
            }

            if ($fresh->status === MarketOrderSweep::STATUS_RUNNING) {
                if ($fresh->pages_total > 0 && $fresh->pages_fetched < $fresh->pages_total) {
                    return false;
                }

                $fresh->update(['status' => MarketOrderSweep::STATUS_COMPLETING]);

                return true;
            }

            return $fresh->status === MarketOrderSweep::STATUS_COMPLETING;
        });
    }

    /**
     * Queue precompute for the region (T1, as the 4-hour cron does by
     * default), at most once per PRECOMPUTE_MIN_INTERVAL_MINUTES so a
     * 15-minute Jita sweep does not flood the single "updates" worker.
     */
    protected function triggerPrecompute(int $regionId): void
    {
        $key = self::PRECOMPUTE_TRIGGER_PREFIX.$regionId;

        if (! Cache::add($key, now()->toIso8601String(), self::PRECOMPUTE_MIN_INTERVAL_MINUTES * 60)) {
            Log::info("MarketOrderSweep: precompute for region {$regionId} already triggered in the last ".self::PRECOMPUTE_MIN_INTERVAL_MINUTES.' minutes');

            return;
        }

        PrecomputeManufacturingCostsChunk::dispatch($regionId, [EveType::META_GROUP_TECH_I], false);
    }

    /**
     * @param  array<string, int>  $columns
     */
    protected function increment(string $sweepId, array $columns): void
    {
        $columns = array_filter($columns);
        if ($columns === []) {
            return;
        }

        DB::table('market_order_sweeps')
            ->where('sweep_id', $sweepId)
            ->incrementEach($columns, ['updated_at' => now()]);
    }

    /**
     * Retry a write on MySQL deadlock (1213) with a short back-off.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function retryOnDeadlock(callable $callback): mixed
    {
        return retry(3, $callback, fn (int $attempt) => 100 * $attempt, fn ($e) => $e instanceof QueryException
            && (($e->errorInfo[1] ?? null) === 1213));
    }
}
