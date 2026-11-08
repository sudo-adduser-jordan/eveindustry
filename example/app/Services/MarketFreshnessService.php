<?php

namespace App\Services;

use App\Models\MarketOrderSweep;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reports how old the market data behind prices and precomputed costs is.
 *
 * Timestamp sources:
 * - orders_as_of: start of the region's active completed order sweep
 *   (market_region_states), the snapshot market_aggregates and prices come
 *   from. A region never swept falls back to MAX(market_orders.updated_at).
 *   (`issued` is when a player placed the order, not when we saw it.)
 * - costs_as_of:  MAX(manufacturing_costs.updated_at) per region, written by
 *   PrecomputeManufacturingCostsChunk.
 * - global_as_of: MAX(market_prices.fetched_at) for the ESI global price rows,
 *   which are stored under The Forge (10000002). Same value for every region.
 */
class MarketFreshnessService
{
    /** Region the ESI global (average/adjusted) prices are stored under. */
    public const GLOBAL_PRICES_REGION_ID = 10000002;

    /** Jita is swept every 15-30 min and the other hubs hourly; allow 1 h slack. */
    public const ORDERS_STALE_AFTER_HOURS = 2;

    /** Costs are precomputed every 4 h; allow 1 h slack. */
    public const COSTS_STALE_AFTER_HOURS = 5;

    /** Global prices update daily at 04:00 UTC; allow 2 h slack. */
    public const GLOBAL_STALE_AFTER_HOURS = 26;

    /** The underlying MAX() queries scan large tables, so results are briefly cached. */
    public const CACHE_TTL_SECONDS = 60;

    public const CACHE_KEY = 'market.freshness.v1';

    public const CONTRACTS_CACHE_KEY = 'market.freshness.contracts.v1';

    /** Public contracts are imported hourly; allow 1 h slack. */
    public const CONTRACTS_STALE_AFTER_HOURS = 2;

    /**
     * Freshness for every trade region, keyed by region id.
     *
     * @return array<int, array{region_id: int, region_name: string, orders_as_of: ?string, costs_as_of: ?string, global_as_of: ?string, stale: bool}>
     */
    public function regions(): array
    {
        $timestamps = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn () => $this->loadTimestamps());

        $result = [];
        foreach (MarketTradingService::REGIONS as $regionId => $regionName) {
            $orders = $timestamps['orders'][$regionId] ?? null;
            $costs = $timestamps['costs'][$regionId] ?? null;
            $global = $timestamps['global'];

            $result[$regionId] = [
                'region_id' => $regionId,
                'region_name' => $regionName,
                'orders_as_of' => $this->iso($orders),
                'costs_as_of' => $this->iso($costs),
                'global_as_of' => $this->iso($global),
                'stale' => $this->isStale($orders, $costs, $global),
            ];
        }

        return $result;
    }

    /**
     * The `meta.freshness` block for price and cost responses in one region.
     *
     * `source` mirrors MarketService::getSellPrices(): regional orders when the
     * region has any, otherwise the ESI global prices.
     *
     * @return array{prices_as_of: ?string, costs_as_of: ?string, source: string, stale: bool}
     */
    public function freshnessMeta(int $regionId): array
    {
        $region = $this->regions()[$regionId] ?? null;

        if ($region === null) {
            return ['prices_as_of' => null, 'costs_as_of' => null, 'source' => 'none', 'stale' => true];
        }

        $usesOrders = $region['orders_as_of'] !== null;

        return [
            'prices_as_of' => $usesOrders ? $region['orders_as_of'] : $region['global_as_of'],
            'costs_as_of' => $region['costs_as_of'],
            'source' => $usesOrders ? 'market_orders' : ($region['global_as_of'] !== null ? 'market_prices' : 'none'),
            'stale' => $region['stale'],
        ];
    }

    /**
     * `meta.freshness` for each of several regions, keyed by region id, for
     * responses that combine regions (e.g. region arbitrage).
     *
     * @param  list<int>  $regionIds
     * @return array<int, array{prices_as_of: ?string, costs_as_of: ?string, source: string, stale: bool}>
     */
    public function freshnessMetaByRegion(array $regionIds): array
    {
        $result = [];
        foreach (array_unique($regionIds) as $regionId) {
            $result[(int) $regionId] = $this->freshnessMeta((int) $regionId);
        }

        return $result;
    }

    /**
     * `meta.freshness` for prices taken from public contracts (contract
     * appraisal). Contracts are pooled across all regions, so this is not
     * per region: prices_as_of is the last contracts:import, MAX(public_contracts.updated_at).
     * Same keys as freshnessMeta(); costs_as_of is always null.
     *
     * @return array{prices_as_of: ?string, costs_as_of: null, source: string, stale: bool}
     */
    public function contractsFreshnessMeta(): array
    {
        $asOf = Cache::remember(self::CONTRACTS_CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            $generationId = DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id');
            $dates = $generationId === null ? null : DB::table('public_contract_snapshot_contracts')
                ->where('generation_id', $generationId)
                ->selectRaw('MAX(http_last_modified) AS source_at, MAX(updated_at) AS stored_at')->first();
            // Publishing/backfilling a generation does not make its source newer.
            // An empty book supplies no contract prices, even if the import succeeded.
            $max = $dates?->source_at ?? $dates?->stored_at;

            return $max !== null ? (string) $max : null;
        });

        return [
            'prices_as_of' => $this->iso($asOf),
            'costs_as_of' => null,
            'source' => $asOf !== null ? 'public_contracts' : 'none',
            'stale' => $this->olderThan($asOf, self::CONTRACTS_STALE_AFTER_HOURS),
        ];
    }

    /**
     * Stale when any source is missing or older than its threshold.
     */
    public function isStale(?string $ordersAsOf, ?string $costsAsOf, ?string $globalAsOf): bool
    {
        return $this->olderThan($ordersAsOf, self::ORDERS_STALE_AFTER_HOURS)
            || $this->olderThan($costsAsOf, self::COSTS_STALE_AFTER_HOURS)
            || $this->olderThan($globalAsOf, self::GLOBAL_STALE_AFTER_HOURS);
    }

    /**
     * @return array{orders: array<int, string>, costs: array<int, string>, global: ?string}
     */
    private function loadTimestamps(): array
    {
        $regionIds = array_keys(MarketTradingService::REGIONS);

        // The durable active pointer is the generation prices are read from.
        $swept = DB::table('market_region_states')
            ->whereIn('region_id', $regionIds)
            ->whereNotNull('active_sweep_id')
            ->pluck('active_snapshot_at', 'region_id')
            ->all();

        // Regions with no durable pointer retain a safe legacy fallback.
        $legacyRegions = array_values(array_diff($regionIds, array_keys(array_filter($swept))));
        $legacySwept = $legacyRegions === [] ? [] : DB::table('market_order_sweeps')
            ->whereIn('region_id', $legacyRegions)
            ->where('status', MarketOrderSweep::STATUS_COMPLETED)
            ->groupBy('region_id')
            ->selectRaw('region_id, MAX(started_at) as as_of')
            ->pluck('as_of', 'region_id')
            ->all();
        $swept = array_filter($swept) + $legacySwept;

        // Regions never swept fall back to the last raw order write.
        $unswept = array_values(array_diff($regionIds, array_keys(array_filter($swept))));
        $orders = $unswept === [] ? [] : DB::table('market_orders')
            ->whereIn('region_id', $unswept)
            ->groupBy('region_id')
            ->selectRaw('region_id, MAX(updated_at) as as_of')
            ->pluck('as_of', 'region_id')
            ->all();
        $orders = array_filter($swept) + $orders;

        $costs = DB::table('manufacturing_costs')
            ->whereIn('region_id', $regionIds)
            ->groupBy('region_id')
            ->selectRaw('region_id, MAX(updated_at) as as_of')
            ->pluck('as_of', 'region_id')
            ->all();

        $global = DB::table('market_prices')
            ->where('region_id', self::GLOBAL_PRICES_REGION_ID)
            ->max('fetched_at');

        return [
            'orders' => array_map('strval', array_filter($orders)),
            'costs' => array_map('strval', array_filter($costs)),
            'global' => $global !== null ? (string) $global : null,
        ];
    }

    private function olderThan(?string $timestamp, int $hours): bool
    {
        if ($timestamp === null) {
            return true;
        }

        return CarbonImmutable::parse($timestamp, 'UTC')->lt(CarbonImmutable::now()->subHours($hours));
    }

    private function iso(?string $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp, 'UTC')->utc()->toIso8601ZuluString();
    }
}
