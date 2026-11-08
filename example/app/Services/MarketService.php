<?php

namespace App\Services;

use App\Models\EveType;
use App\Models\MarketOrder;
use App\Models\MarketPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class MarketService
{
    public const DEFAULT_REGION_ID = 10000002; // The Forge (Jita)

    // Longest an EVE market order can stay open.
    public const MAX_ORDER_AGE_DAYS = 90;

    public const PRICE_SOURCE_ORDERS = 'orders';

    public const PRICE_SOURCE_FALLBACK = 'fallback';

    public const PRICE_SOURCE_NONE = 'none';

    public function __construct(
        protected EsiService $esi,
        protected ?MarketAggregateService $aggregates = null,
    ) {}

    /**
     * Get all published type IDs that have a market group (i.e. are tradeable on the market).
     *
     * Uses the local eve_types table populated by the SDE import.
     *
     * @return array<int>
     */
    public function getMarketableTypeIds(): array
    {
        return EveType::where('published', true)
            ->whereNotNull('market_group_id')
            ->pluck('type_id')
            ->all();
    }

    /**
     * Get the stored market price for a type in a region.
     */
    public function getPrice(int $typeId, int $regionId = self::DEFAULT_REGION_ID): ?MarketPrice
    {
        return MarketPrice::where('type_id', $typeId)
            ->where('region_id', $regionId)
            ->first();
    }

    /**
     * Get prices for multiple type IDs at once.
     */
    public function getPrices(array $typeIds, int $regionId = self::DEFAULT_REGION_ID): array
    {
        $prices = MarketPrice::whereIn('type_id', $typeIds)
            ->where('region_id', $regionId)
            ->get()
            ->keyBy('type_id');

        $result = [];
        foreach ($typeIds as $typeId) {
            $result[$typeId] = $prices->get($typeId);
        }

        return $result;
    }

    /**
     * Get sell prices for multiple type IDs using the "average sell 5%" method.
     *
     * This is the one price source for calculators, browsers and precomputed
     * costs. See getSellPricesWithSource() for how each price is resolved.
     *
     * @param  int  $maxDays  Maximum order age in days (EVE orders last at most 90)
     * @param  float  $percentile  Volume percentile to average (default 5%)
     * @return array<int, float> type_id => price
     */
    public function getSellPrices(array $typeIds, int $regionId = self::DEFAULT_REGION_ID, int $maxDays = self::MAX_ORDER_AGE_DAYS, float $percentile = 5.0): array
    {
        return array_map(
            fn (array $entry) => $entry['price'],
            $this->getSellPricesWithSource($typeIds, $regionId, $maxDays, $percentile)
        );
    }

    /**
     * Get sell prices for multiple type IDs along with where each price came from.
     *
     * For each type:
     *  1. orders:   volume-weighted average of the cheapest 5% of active sell
     *               orders in the region. Once a region sweep has completed
     *               this comes from market_aggregates (the last complete
     *               sweep, cached per sweep); before that, and for
     *               non-default $maxDays / $percentile, from raw market_orders.
     *  2. fallback: sell_min / average_price from market_prices for the region,
     *               then for Jita, which also holds the ESI global averages.
     *               Only Jita has global price rows, so without this other
     *               hubs priced at 0.
     *  3. none:     no source has a price; the price is 0.
     *
     * @return array<int, array{price: float, source: string}> type_id => entry
     */
    public function getSellPricesWithSource(array $typeIds, int $regionId = self::DEFAULT_REGION_ID, int $maxDays = self::MAX_ORDER_AGE_DAYS, float $percentile = 5.0): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $result = [];

        $orderPrices = $this->orderSellPrices($typeIds, $regionId, $maxDays, $percentile);

        $missingTypeIds = [];
        foreach ($typeIds as $typeId) {
            $price = $orderPrices[$typeId] ?? 0.0;
            if ($price <= 0) {
                $missingTypeIds[] = $typeId;

                continue;
            }

            $result[$typeId] = ['price' => (float) $price, 'source' => self::PRICE_SOURCE_ORDERS];
        }

        if (empty($missingTypeIds)) {
            return $result;
        }

        // Fallback: market_prices for the region, then Jita
        $fallbackRegions = array_values(array_unique([$regionId, self::DEFAULT_REGION_ID]));
        $fallbackPrices = MarketPrice::whereIn('type_id', $missingTypeIds)
            ->whereIn('region_id', $fallbackRegions)
            ->get()
            ->groupBy('region_id');

        foreach ($missingTypeIds as $typeId) {
            $price = 0.0;
            foreach ($fallbackRegions as $fallbackRegion) {
                $mp = $fallbackPrices->get($fallbackRegion)?->firstWhere('type_id', $typeId);
                $price = $mp ? $this->marketPriceValue($mp) : 0.0;
                if ($price > 0) {
                    break;
                }
            }

            $result[$typeId] = [
                'price' => $price,
                'source' => $price > 0 ? self::PRICE_SOURCE_FALLBACK : self::PRICE_SOURCE_NONE,
            ];
        }

        return $result;
    }

    /**
     * 5th-percentile sell price per type from regional orders (0 or absent
     * when the type has no sell orders).
     *
     * With the default window and percentile, and once the region has a
     * completed sweep, the figure is read from market_aggregates through
     * MarketAggregateService (cached per sweep). Otherwise it is computed
     * from raw market_orders.
     *
     * @return array<int, float>
     */
    private function orderSellPrices(array $typeIds, int $regionId, int $maxDays, float $percentile): array
    {
        if ($maxDays === self::MAX_ORDER_AGE_DAYS && $percentile === 5.0) {
            $aggregated = $this->aggregates()->sellPrices($typeIds, $regionId);
            if ($aggregated !== null) {
                return $aggregated;
            }
        }

        // Batch-fetch all active sell orders for the requested types. Orders live
        // up to 90 days, so a shorter cut-off drops valid orders.
        $orders = MarketOrder::whereIn('type_id', $typeIds)
            ->where('region_id', $regionId)
            ->where('is_buy_order', false)
            ->where('volume_remain', '>', 0)
            ->where('issued', '>=', now()->subDays($maxDays))
            ->get(['type_id', 'price', 'volume_remain']);

        $prices = [];
        foreach ($orders->groupBy('type_id') as $typeId => $typeOrders) {
            $prices[(int) $typeId] = $this->computePercentileAverage($typeOrders, $percentile);
        }

        return $prices;
    }

    private function aggregates(): MarketAggregateService
    {
        return $this->aggregates ??= app(MarketAggregateService::class);
    }

    /**
     * Daily market history for one type in one region, newest first.
     *
     * Reads the market_history table (filled by the everef import) through
     * EsiService::getMarketRegionHistory(). Returns the $days most recent
     * daily rows that exist, so a lagging import still yields data.
     *
     * @return list<array{date: string, average: float, highest: float, lowest: float, volume: int, order_count: int}>
     */
    public function getHistory(int $typeId, int $regionId = self::DEFAULT_REGION_ID, int $days = 30): array
    {
        $rows = $this->esi->getMarketRegionHistory($regionId, $typeId) ?? [];

        return array_values(array_slice(
            collect($rows)->sortByDesc('date')->values()->all(),
            0,
            max($days, 0),
        ));
    }

    /**
     * Current orders for one type in one region, best first: sell orders by
     * price ascending, buy orders by price descending (ties by order id).
     *
     * @param  string  $side  'sell' or 'buy'
     * @return Builder<MarketOrder>
     */
    public function ordersQuery(int $typeId, int $regionId = self::DEFAULT_REGION_ID, string $side = 'sell'): Builder
    {
        $isBuy = $side === 'buy';

        return MarketOrder::query()
            ->where('region_id', $regionId)
            ->where('type_id', $typeId)
            ->where('is_buy_order', $isBuy)
            ->where('volume_remain', '>', 0)
            ->orderBy('price', $isBuy ? 'desc' : 'asc')
            ->orderBy('order_id');
    }

    /**
     * Best price on a market_prices row: lowest sell, else the average.
     */
    private function marketPriceValue(MarketPrice $mp): float
    {
        foreach ([$mp->sell_min, $mp->average_price] as $value) {
            if ($value !== null && (float) $value > 0) {
                return (float) $value;
            }
        }

        return 0.0;
    }

    /**
     * Compute the volume-weighted average price of the cheapest N% of sell volume.
     *
     * Orders are sorted by price ascending. We walk through them accumulating
     * volume until we reach the target percentile of total volume, then return
     * the volume-weighted average of those orders.
     */
    private function computePercentileAverage(\Illuminate\Support\Collection $orders, float $percentile): float
    {
        $pairs = $orders->sortBy('price')
            ->map(fn ($order) => [(float) $order->price, (int) $order->volume_remain])
            ->values()
            ->all();

        return self::percentileAverage($pairs, $percentile);
    }

    /**
     * The "average sell 5%" maths, shared with MarketAggregateService so the
     * aggregates hold exactly the figure getSellPrices() would compute.
     *
     * @param  list<array{0: float, 1: int}>  $sortedOrders  [price, volume_remain] pairs, cheapest first
     */
    public static function percentileAverage(array $sortedOrders, float $percentile = 5.0): float
    {
        $totalVolume = 0;
        foreach ($sortedOrders as [, $volume]) {
            $totalVolume += $volume;
        }

        if ($totalVolume <= 0) {
            return 0;
        }

        $targetVolume = $totalVolume * ($percentile / 100.0);
        // Ensure at least 1 unit is included
        $targetVolume = max($targetVolume, 1);

        $accumulatedVolume = 0;
        $weightedSum = 0;

        foreach ($sortedOrders as [$price, $vol]) {

            if ($accumulatedVolume + $vol <= $targetVolume) {
                // Include the full order
                $weightedSum += $price * $vol;
                $accumulatedVolume += $vol;
            } else {
                // Include only the portion needed to reach the target
                $needed = $targetVolume - $accumulatedVolume;
                $weightedSum += $price * $needed;
                $accumulatedVolume += $needed;

                break;
            }
        }

        return $accumulatedVolume > 0 ? round($weightedSum / $accumulatedVolume, 2) : 0;
    }

    /**
     * Fetch and store market data for a single type from ESI.
     */
    public function fetchAndStorePrice(int $typeId, int $regionId = self::DEFAULT_REGION_ID): ?MarketPrice
    {
        try {
            Log::info("MarketService: fetching price data for type {$typeId} in region {$regionId}");

            $sellOrders = $this->esi->getMarketRegionOrders($regionId, $typeId, 'sell');
            $buyOrders = $this->esi->getMarketRegionOrders($regionId, $typeId, 'buy');
            $history = $this->esi->getMarketRegionHistory($regionId, $typeId);

            $sellMin = null;
            $buyMax = null;

            if ($sellOrders && count($sellOrders) > 0) {
                $sellMin = collect($sellOrders)->min('price');
            }

            if ($buyOrders && count($buyOrders) > 0) {
                $buyMax = collect($buyOrders)->max('price');
            }

            Log::info("MarketService: type {$typeId} region {$regionId} — sell orders: ".($sellOrders ? count($sellOrders) : 'null').', buy orders: '.($buyOrders ? count($buyOrders) : 'null').', history: '.($history ? count($history) : 'null'));

            // Calculate 30-day averages from history
            $sellVolume = 0;
            $buyVolume = 0;
            $totalVolume = 0;
            $avgPrice = null;
            $days = 0;

            if ($history && count($history) > 0) {
                $recent = collect($history)->sortByDesc('date')->take(30);
                $days = $recent->count();

                $totalVolume = $recent->sum('volume');
                $avgPrice = $recent->avg('average');

                // ESI history doesn't separate buy/sell volume, so use total
                $sellVolume = $totalVolume;
                $buyVolume = $totalVolume;
            }

            $dailyVolume = $days > 0 ? intdiv($totalVolume, $days) : 0;

            return MarketPrice::updateOrCreate(
                ['type_id' => $typeId, 'region_id' => $regionId],
                [
                    'average_price' => $avgPrice,
                    'sell_min' => $sellMin,
                    'buy_max' => $buyMax,
                    'sell_volume' => $sellVolume,
                    'buy_volume' => $buyVolume,
                    'daily_volume' => $dailyVolume,
                    'fetched_at' => now(),
                ]
            );
        } catch (\Exception $e) {
            Log::error("Error fetching market price for type {$typeId} in region {$regionId}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Fetch and store the global adjusted/average prices from ESI.
     */
    public function fetchAndStoreGlobalPrices(): int
    {
        Log::info('MarketService: fetching global market prices');
        $prices = $this->esi->getMarketPrices();
        if (! $prices) {
            Log::warning('MarketService: global market prices returned empty/null');

            return 0;
        }

        Log::info('MarketService: processing '.count($prices).' global price entries');
        $count = 0;
        foreach ($prices as $priceData) {
            $typeId = $priceData['type_id'] ?? null;
            if (! $typeId) {
                continue;
            }

            MarketPrice::updateOrCreate(
                ['type_id' => $typeId, 'region_id' => self::DEFAULT_REGION_ID],
                [
                    'average_price' => $priceData['average_price'] ?? null,
                    'adjusted_price' => $priceData['adjusted_price'] ?? null,
                    'fetched_at' => now(),
                ]
            );
            $count++;
        }

        Log::info("MarketService: stored {$count} global price entries");

        return $count;
    }

    /**
     * Fetch regional sell/buy prices for multiple type IDs.
     */
    public function fetchAndStoreRegionalPrices(array $typeIds, int $regionId = self::DEFAULT_REGION_ID): int
    {
        Log::info('MarketService: fetching regional prices for '.count($typeIds)." types in region {$regionId}");
        $count = 0;
        foreach ($typeIds as $typeId) {
            $result = $this->fetchAndStorePrice($typeId, $regionId);
            if ($result) {
                $count++;
            }
        }
        Log::info("MarketService: stored regional prices for {$count}/".count($typeIds)." types in region {$regionId}");

        return $count;
    }

    /**
     * Format an ISK value for display.
     */
    public static function formatIsk(float $value): string
    {
        if ($value >= 1000000000) {
            return number_format($value / 1000000000, 2).'B';
        }
        if ($value >= 1000000) {
            return number_format($value / 1000000, 2).'M';
        }
        if ($value >= 1000) {
            return number_format($value / 1000, 2).'K';
        }

        return number_format($value, 2);
    }
}
