<?php

namespace App\Services;

use App\Models\MarketAggregate;
use App\Models\MarketOrder;
use App\Models\MarketOrderSweep;
use App\Models\MarketRegionState;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Builds and reads immutable market_aggregates generations, rebuilt from
 * market_orders when a region sweep completes.
 *
 * Readers follow the durable per-region pointer to a complete sweep. Builds
 * remain invisible until publication and price cache keys include that sweep.
 */
class MarketAggregateService
{
    /** Types per query when computing the 5th-percentile sell price. */
    public const TYPE_CHUNK = 500;

    /** Rows per upsert. */
    public const UPSERT_CHUNK = 500;

    /** Lifetime of one cached per-type price; a new sweep uses new keys anyway. */
    public const PRICE_CACHE_TTL = 7200;

    /** How long the "current snapshot for this region" pointer is cached. */
    public const SNAPSHOT_CACHE_TTL = 300;

    public const SNAPSHOT_CACHE_PREFIX = 'market.aggregates.snapshot.';

    public const PRICE_CACHE_PREFIX = 'market.aggregates.p5.';

    /**
     * Rebuild the region's aggregates from its current market_orders.
     *
     * Same filters as MarketService::getSellPricesWithSource(): orders with
     * volume left, issued within the last 90 days. Types no longer on the
     * market are absent from this generation; prior generations are retained.
     *
     * @return int number of types written
     */
    public function rebuild(int $regionId, string $sweepId, CarbonInterface $snapshotAt): int
    {
        $start = microtime(true);
        $now = now();
        $cutoff = now()->subDays(MarketService::MAX_ORDER_AGE_DAYS);

        $base = fn () => MarketOrder::query()
            ->where('region_id', $regionId)
            ->where('volume_remain', '>', 0)
            ->where('issued', '>=', $cutoff);

        // Best bid/ask, volumes and order counts per type and side in one pass.
        $sides = $base()
            ->groupBy('type_id', 'is_buy_order')
            ->selectRaw('type_id, is_buy_order, MIN(price) as min_price, MAX(price) as max_price, SUM(volume_remain) as volume, COUNT(*) as orders')
            ->toBase()
            ->get();

        $rows = [];
        foreach ($sides as $side) {
            $typeId = (int) $side->type_id;
            $rows[$typeId] ??= [
                'region_id' => $regionId,
                'type_id' => $typeId,
                'sweep_id' => $sweepId,
                'snapshot_at' => $snapshotAt,
                'best_bid' => null,
                'best_ask' => null,
                'sell_price_p5' => null,
                'sell_volume' => 0,
                'buy_volume' => 0,
                'sell_orders' => 0,
                'buy_orders' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ((bool) $side->is_buy_order) {
                $rows[$typeId]['best_bid'] = (float) $side->max_price;
                $rows[$typeId]['buy_volume'] = (int) $side->volume;
                $rows[$typeId]['buy_orders'] = (int) $side->orders;
            } else {
                $rows[$typeId]['best_ask'] = (float) $side->min_price;
                $rows[$typeId]['sell_volume'] = (int) $side->volume;
                $rows[$typeId]['sell_orders'] = (int) $side->orders;
            }
        }

        // 5th-percentile sell price: walk each type's sell orders cheapest
        // first, a chunk of types at a time so memory stays bounded.
        $sellTypeIds = array_keys(array_filter($rows, fn ($row) => $row['sell_orders'] > 0));
        foreach (array_chunk($sellTypeIds, self::TYPE_CHUNK) as $typeChunk) {
            $orders = $base()
                ->where('is_buy_order', false)
                ->whereIn('type_id', $typeChunk)
                ->orderBy('type_id')
                ->orderBy('price')
                ->toBase()
                ->get(['type_id', 'price', 'volume_remain']);

            $byType = [];
            foreach ($orders as $order) {
                $byType[(int) $order->type_id][] = [(float) $order->price, (int) $order->volume_remain];
            }

            foreach ($byType as $typeId => $pairs) {
                $price = MarketService::percentileAverage($pairs);
                $rows[$typeId]['sell_price_p5'] = $price > 0 ? $price : null;
            }
        }

        foreach (array_chunk(array_values($rows), self::UPSERT_CHUNK) as $chunk) {
            MarketAggregate::upsert($chunk, ['region_id', 'sweep_id', 'type_id'], [
                'snapshot_at', 'best_bid', 'best_ask', 'sell_price_p5',
                'sell_volume', 'buy_volume', 'sell_orders', 'buy_orders', 'updated_at',
            ]);
        }

        $elapsed = round(microtime(true) - $start, 2);
        Log::info('MarketAggregateService: region '.$regionId.' aggregated '.count($rows)." types in {$elapsed}s (sweep {$sweepId})");

        return count($rows);
    }

    /**
     * Atomically point readers at a completed generation if it is still the
     * newest started generation. A late completion cannot move the pointer
     * backwards or publish over a newer sweep.
     */
    public function publishSnapshot(int $regionId, string $sweepId, CarbonInterface $snapshotAt): void
    {
        $this->ensureRegionState($regionId);

        $published = DB::transaction(function () use ($regionId, $sweepId, $snapshotAt): bool {
            $state = MarketRegionState::where('region_id', $regionId)->lockForUpdate()->first();
            $sweep = MarketOrderSweep::where('region_id', $regionId)
                ->where('sweep_id', $sweepId)
                ->where('status', MarketOrderSweep::STATUS_COMPLETED)
                ->lockForUpdate()
                ->first();

            if ($state === null || $sweep === null
                || $state->latest_sweep_id !== $sweepId
                || $state->latest_generation !== $sweep->generation) {
                return false;
            }

            if ($sweep->generation < $state->active_generation) {
                return false;
            }

            if ($sweep->generation === $state->active_generation && $state->active_sweep_id === $sweepId) {
                return true;
            }

            if ($sweep->generation <= $state->active_generation) {
                return false;
            }

            $state->update([
                'active_generation' => $sweep->generation,
                'active_sweep_id' => $sweepId,
                'active_snapshot_at' => $snapshotAt,
            ]);

            return true;
        });

        if ($published) {
            Cache::put(self::SNAPSHOT_CACHE_PREFIX.$regionId, [
                'sweep_id' => $sweepId,
                'snapshot_at' => $snapshotAt->toIso8601ZuluString(),
            ], self::SNAPSHOT_CACHE_TTL);
        }
    }

    /**
     * The active completed generation, or null when no sweep has completed
     * for the region yet.
     *
     * @return array{sweep_id: string, snapshot_at: string}|null
     */
    public function currentSnapshot(int $regionId): ?array
    {
        $snapshot = Cache::remember(self::SNAPSHOT_CACHE_PREFIX.$regionId, self::SNAPSHOT_CACHE_TTL, function () use ($regionId) {
            $state = MarketRegionState::where('region_id', $regionId)->first(['active_sweep_id', 'active_snapshot_at']);

            if ($state?->active_sweep_id !== null) {
                return [
                    'sweep_id' => $state->active_sweep_id,
                    'snapshot_at' => $state->active_snapshot_at->toIso8601ZuluString(),
                ];
            }

            // Migration-safe fallback for regions without a backfilled state
            // pointer. This also keeps pre-generation deployments readable.
            $sweep = MarketOrderSweep::where('region_id', $regionId)
                ->where('status', MarketOrderSweep::STATUS_COMPLETED)
                ->orderByDesc('finished_at')
                ->orderByDesc('id')
                ->first(['sweep_id', 'started_at']);

            // false (not null) so "no snapshot yet" is cached too.
            return $sweep === null ? false : [
                'sweep_id' => $sweep->sweep_id,
                'snapshot_at' => $sweep->started_at->toIso8601ZuluString(),
            ];
        });

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * 5th-percentile sell prices from the region's current snapshot.
     *
     * Returns null when the region has no completed sweep (callers then use
     * raw orders). Otherwise every requested type is present: its price, or
     * 0.0 when it had no sell orders in the snapshot.
     *
     * @param  list<int>  $typeIds
     * @return array<int, float>|null
     */
    public function sellPrices(array $typeIds, int $regionId): ?array
    {
        $snapshot = $this->currentSnapshot($regionId);
        if ($snapshot === null) {
            return null;
        }

        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));
        if ($typeIds === []) {
            return [];
        }

        $prefix = self::PRICE_CACHE_PREFIX."{$regionId}.{$snapshot['sweep_id']}.";
        $keys = array_map(fn (int $typeId) => $prefix.$typeId, $typeIds);
        $cached = Cache::many($keys);

        $prices = [];
        $missing = [];
        foreach ($typeIds as $i => $typeId) {
            $value = $cached[$keys[$i]] ?? null;
            if ($value === null) {
                $missing[] = $typeId;
            } else {
                $prices[$typeId] = (float) $value;
            }
        }

        if ($missing !== []) {
            $fromDb = [];
            foreach (array_chunk($missing, 1000) as $chunk) {
                $fromDb += DB::table('market_aggregates')
                    ->where('region_id', $regionId)
                    ->where('sweep_id', $snapshot['sweep_id'])
                    ->whereIn('type_id', $chunk)
                    ->pluck('sell_price_p5', 'type_id')
                    ->all();
            }

            $toCache = [];
            foreach ($missing as $typeId) {
                $price = (float) ($fromDb[$typeId] ?? 0.0);
                $prices[$typeId] = $price;
                $toCache[$prefix.$typeId] = $price;
            }
            Cache::putMany($toCache, self::PRICE_CACHE_TTL);
        }

        return $prices;
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
}
