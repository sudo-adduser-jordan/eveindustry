<?php

namespace App\Services;

use App\Models\EveType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarketTradingService
{
    // Well-known hub region IDs
    public const REGION_THE_FORGE    = 10000002; // Jita
    public const REGION_DOMAIN       = 10000043; // Amarr
    public const REGION_SINQ_LAISON  = 10000032; // Dodixie
    public const REGION_HEIMATAR     = 10000030; // Rens
    public const REGION_METROPOLIS   = 10000042; // Hek

    public const REGIONS = [
        self::REGION_THE_FORGE   => 'The Forge (Jita)',
        self::REGION_DOMAIN      => 'Domain (Amarr)',
        self::REGION_SINQ_LAISON => 'Sinq Laison (Dodixie)',
        self::REGION_HEIMATAR    => 'Heimatar (Rens)',
        self::REGION_METROPOLIS  => 'Metropolis (Hek)',
    ];

    // Jita 4-4 station ID used to filter station-only orders
    public const JITA_STATION_ID = 60003760;

    // Scan defaults shared by the old /api/market/* routes and /api/v1/market/*.
    public const DEFAULT_BROKER_FEE = 3.0;

    public const DEFAULT_SALES_TAX = 3.6;

    public const DEFAULT_MIN_MARGIN = 5.0;

    public const DEFAULT_MIN_DAILY_VOLUME = 5;

    public const DEFAULT_TRANSPORT_ISK_M3 = 0.0;

    /** Result cap the old routes apply when no `limit` is given. */
    public const DEFAULT_LIMIT = 100;

    public function __construct(protected EsiService $esi)
    {
    }

    /**
     * Display name for a region: the trade hub label, or "Region {id}".
     */
    public static function regionName(int $regionId): string
    {
        return self::REGIONS[$regionId] ?? "Region {$regionId}";
    }

    /**
     * Calculate the net profit per unit for a station-trading flip.
     *
     * Station trading: place a buy order (pay broker fee), wait for fill,
     * then post a sell order (pay broker fee + sales tax on proceeds).
     *
     * @param  float  $buyPrice   Highest existing buy order (what you will match/undercut by 0.01)
     * @param  float  $sellPrice  Lowest existing sell order (what you will match/undercut by 0.01)
     * @param  float  $brokerFeePercent  Combined broker fee % (buy + sell orders, default 3%)
     * @param  float  $salesTaxPercent   Sales tax % on sell proceeds (default 3.6%)
     * @return array{gross_margin_pct: float, net_profit_per_unit: float, net_margin_pct: float, roi_pct: float, break_even: float}
     */
    public function calcStationTradeProfit(
        float $buyPrice,
        float $sellPrice,
        float $brokerFeePercent = 3.0,
        float $salesTaxPercent = 3.6
    ): array {
        if ($buyPrice <= 0 || $sellPrice <= 0 || $sellPrice <= $buyPrice) {
            return [
                'gross_margin_pct'    => 0.0,
                'net_profit_per_unit' => 0.0,
                'net_margin_pct'      => 0.0,
                'roi_pct'             => 0.0,
                'break_even'          => 0.0,
            ];
        }

        $grossMarginPct = (($sellPrice - $buyPrice) / $sellPrice) * 100.0;

        // Cost to place the buy order (broker fee on order value)
        $buyBrokerCost = $buyPrice * ($brokerFeePercent / 100.0);
        // Proceeds after sell-order broker fee and sales tax
        $sellBrokerCost = $sellPrice * ($brokerFeePercent / 100.0);
        $salesTaxCost   = $sellPrice * ($salesTaxPercent / 100.0);

        $netProfitPerUnit = $sellPrice - $buyPrice - $buyBrokerCost - $sellBrokerCost - $salesTaxCost;
        $netMarginPct     = ($netProfitPerUnit / $sellPrice) * 100.0;
        $totalCost        = $buyPrice + $buyBrokerCost;
        $roiPct           = $totalCost > 0 ? ($netProfitPerUnit / $totalCost) * 100.0 : 0.0;

        // Break-even: minimum sell price to cover all fees
        $breakEven = ($buyPrice + $buyBrokerCost) / (1.0 - ($brokerFeePercent + $salesTaxPercent) / 100.0);

        return [
            'gross_margin_pct'    => round($grossMarginPct, 2),
            'net_profit_per_unit' => round($netProfitPerUnit, 2),
            'net_margin_pct'      => round($netMarginPct, 2),
            'roi_pct'             => round($roiPct, 2),
            'break_even'          => round($breakEven, 2),
        ];
    }

    /**
     * Scan a region's current orders for station-trading opportunities.
     *
     * Groups all orders by type, then for each type computes spread metrics.
     * Returns items sorted by estimated daily ISK profit descending.
     *
     * @param  int    $regionId
     * @param  float  $brokerFeePercent
     * @param  float  $salesTaxPercent
     * @param  float  $minMarginPct      Minimum net margin % to include in results
     * @param  int    $minDailyVolume    Minimum 30-day avg daily volume
     * @param  int|null  $limit           Max results to return (null = all)
     * @return array
     */
    public function scanStationTrading(
        int $regionId = self::REGION_THE_FORGE,
        float $brokerFeePercent = 3.0,
        float $salesTaxPercent = 3.6,
        float $minMarginPct = 5.0,
        int $minDailyVolume = 5,
        ?int $limit = 100
    ): array {

        $start = microtime(true);
        $brokerRate = $brokerFeePercent / 100.0;
        $taxRate    = $salesTaxPercent / 100.0;
        $feeSum     = $brokerRate + $taxRate;

        // Interpolate numeric values directly into SQL because SQLite's
        // PDO driver does not reliably bind parameters inside aggregate
        // expressions or across subquery boundaries. All values are computed
        // from PHP numerics, not user strings, so injection is not a concern.
        $br  = number_format($brokerRate, 10, '.', '');
        $tr  = number_format($taxRate, 10, '.', '');
        $fs  = number_format($feeSum, 10, '.', '');
        $rid = (int) $regionId;
        $mmp = number_format($minMarginPct, 10, '.', '');

        $sql = "SELECT * FROM (
                SELECT
                    o.type_id,
                    t.name                                    AS type_name,
                    MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END)  AS best_buy,
                    MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END)  AS best_sell,
                    SUM(CASE WHEN o.is_buy_order = 1 THEN 1 ELSE 0 END) AS buy_order_count,
                    SUM(CASE WHEN o.is_buy_order = 0 THEN 1 ELSE 0 END) AS sell_order_count,

                    /* gross margin */
                    ROUND(
                        ((MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END)
                          - MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END))
                         / MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END)) * 100
                    , 2) AS gross_margin_pct,

                    /* net profit per unit */
                    ROUND(
                        MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END)
                        - MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END)
                        - (MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END) * {$br})
                        - (MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END) * {$br})
                        - (MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END) * {$tr})
                    , 2) AS net_profit_per_unit,

                    /* net margin % */
                    ROUND(
                        ((MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END)
                          - MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END)
                          - (MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END) * {$br})
                          - (MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END) * {$br})
                          - (MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END) * {$tr}))
                         / MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END)) * 100
                    , 2) AS net_margin_pct,

                    /* ROI % = net_profit / (best_buy + buy_broker_cost) * 100 */
                    ROUND(
                        ((MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END)
                          - MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END)
                          - (MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END) * {$br})
                          - (MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END) * {$br})
                          - (MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END) * {$tr}))
                         / (MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END)
                            + MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END) * {$br})) * 100
                    , 2) AS roi_pct,

                    /* break-even sell price */
                    ROUND(
                        (MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END)
                         + MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END) * {$br})
                        / (1.0 - {$fs})
                    , 2) AS break_even

                FROM market_orders o
                LEFT JOIN eve_types t ON t.type_id = o.type_id
                WHERE o.region_id = {$rid}
                GROUP BY o.type_id, t.name
            ) AS candidates
            WHERE best_buy IS NOT NULL
              AND best_sell IS NOT NULL
              AND best_sell > best_buy
              AND net_margin_pct >= {$mmp}
            ORDER BY net_profit_per_unit DESC";

        try {

            $candidates = DB::select($sql);

        } catch (\Exception $e) {
            Log::error("MarketTradingService: SQL error during station trading scan for region {$regionId}: {$e->getMessage()}");
            return [];
        }

        if (empty($candidates)) {
            Log::warning("MarketTradingService: no qualifying spreads for region {$regionId}");
            return [];
        }

        Log::info("MarketTradingService: SQL returned ".count($candidates)." candidates for region {$regionId}");

        $opportunities = [];

        foreach ($candidates as $row) {
            $typeId = (int) $row->type_id;

            // Fetch historical volume data (cached) — this is the one thing
            // that cannot be pushed into SQL because it comes from ESI.
            $history = $this->esi->getMarketRegionHistory($regionId, $typeId);

            $dailyVolume = 0;

            $recent = collect($history)->sortByDesc('date')->take(30);
            $days   = $recent->count();
            $dailyVolume = $days > 0 ? (int) ($recent->sum('volume') / $days) : 0;

            // Only apply the volume floor when we have real history data.
            // If history is unavailable (null = ESI error), we include the
            // item rather than silently dropping every candidate.
            if ($history !== null && $dailyVolume < $minDailyVolume) {
                continue;
            }

            $netProfit = (float) $row->net_profit_per_unit;

            $opportunities[] = [
                'type_id'             => $typeId,
                // Fallback name built in PHP: '||' concatenates on SQLite but is OR on MySQL
                'type_name'           => $row->type_name ?? "Type #{$typeId}",
                'best_buy'            => (float) $row->best_buy,
                'best_sell'           => (float) $row->best_sell,
                'gross_margin_pct'    => (float) $row->gross_margin_pct,
                'net_profit_per_unit' => $netProfit,
                'net_margin_pct'      => (float) $row->net_margin_pct,
                'roi_pct'             => (float) $row->roi_pct,
                'break_even'          => (float) $row->break_even,
                'daily_volume'        => $dailyVolume,
                'daily_isk_profit'    => round($netProfit * $dailyVolume, 0),
                'buy_order_count'     => (int) $row->buy_order_count,
                'sell_order_count'    => (int) $row->sell_order_count,
            ];
        }

        // Sort by daily ISK profit descending
        usort($opportunities, fn($a, $b) => $b['daily_isk_profit'] <=> $a['daily_isk_profit']);

        Log::info("MarketTradingService: station trading scan for region {$regionId} found ".count($opportunities)." opportunities");

        return $limit === null ? $opportunities : array_slice($opportunities, 0, $limit);
    }

    /**
     * Calculate the arbitrage profit per unit for buying in one region and selling in another.
     *
     * @param  float  $buyRegionSellMin  Lowest sell price in the source region (what you buy at)
     * @param  float  $sellRegionBuyMax  Highest buy order in the destination region (what you sell into)
     * @param  float  $brokerFeePercent  Broker fee % on sell order (or buy order in dest region)
     * @param  float  $salesTaxPercent   Sales tax % on sell proceeds
     * @param  float  $transportIskPerM3 Hauling cost per m³ (0 = ignored)
     * @param  float  $volumeM3          Item volume in m³ per unit
     * @return array{net_profit_per_unit: float, net_margin_pct: float, roi_pct: float}
     */
    public function calcArbitrageProfit(
        float $buyRegionSellMin,
        float $sellRegionBuyMax,
        float $brokerFeePercent = 3.0,
        float $salesTaxPercent = 3.6,
        float $transportIskPerM3 = 0.0,
        float $volumeM3 = 0.0
    ): array {
        if ($buyRegionSellMin <= 0 || $sellRegionBuyMax <= 0) {
            return ['net_profit_per_unit' => 0.0, 'net_margin_pct' => 0.0, 'roi_pct' => 0.0];
        }

        $transportCostPerUnit = $transportIskPerM3 * $volumeM3;

        // Selling into existing buy orders: pay sales tax, no broker fee needed for market sell
        $salesTaxCost = $sellRegionBuyMax * ($salesTaxPercent / 100.0);

        $netProfitPerUnit = $sellRegionBuyMax - $buyRegionSellMin - $salesTaxCost - $transportCostPerUnit;
        $netMarginPct     = ($netProfitPerUnit / $sellRegionBuyMax) * 100.0;
        $roiPct           = $buyRegionSellMin > 0 ? ($netProfitPerUnit / $buyRegionSellMin) * 100.0 : 0.0;

        return [
            'net_profit_per_unit' => round($netProfitPerUnit, 2),
            'net_margin_pct'      => round($netMarginPct, 2),
            'roi_pct'             => round($roiPct, 2),
        ];
    }

    /**
     * Scan for arbitrage opportunities between two regions.
     *
     * Compares stored market_prices data (sell_min in source vs buy_max in dest).
     *
     * @param  int    $buyRegionId       Region to buy from (source)
     * @param  int    $sellRegionId      Region to sell into (destination)
     * @param  float  $brokerFeePercent
     * @param  float  $salesTaxPercent
     * @param  float  $transportIskPerM3 Hauling cost per m³ (0 to ignore)
     * @param  float  $minMarginPct
     * @param  int    $minDailyVolume
     * @param  int|null  $limit  Max results to return (null = all)
     * @return array
     */
    public function scanRegionArbitrage(
        int $buyRegionId = self::REGION_THE_FORGE,
        int $sellRegionId = self::REGION_DOMAIN,
        float $brokerFeePercent = 3.0,
        float $salesTaxPercent = 3.6,
        float $transportIskPerM3 = 0.0,
        float $minMarginPct = 5.0,
        int $minDailyVolume = 5,
        ?int $limit = 100
    ): array {
        Log::info("MarketTradingService: scanning region arbitrage from region {$buyRegionId} to region {$sellRegionId}");
        $start = microtime(true);

        // Cheapest sell order per type in the source region (what we buy at)
        // and highest buy order per type in the destination (what we sell into).
        $buyRegionSellMin = $this->esi->getRegionBestPrices($buyRegionId, false);
        $sellRegionBuyMax = $this->esi->getRegionBestPrices($sellRegionId, true);

        if (empty($buyRegionSellMin) || empty($sellRegionBuyMax)) {
            Log::warning("MarketTradingService: empty orders for arbitrage scan — buy region {$buyRegionId}: ".count($buyRegionSellMin).' priced types, sell region '.$sellRegionId.': '.count($sellRegionBuyMax));

            return [];
        }

        Log::info("MarketTradingService: arbitrage scan prices — buy region {$buyRegionId}: ".count($buyRegionSellMin).' types, sell region '.$sellRegionId.': '.count($sellRegionBuyMax));

        $opportunities = [];

        // Pre-load type names and volumes from local SDE data in a single query
        $candidateTypeIds = array_keys(array_intersect_key($buyRegionSellMin, $sellRegionBuyMax));
        $localTypes = collect();
        try {
            $localTypes = EveType::whereIn('type_id', $candidateTypeIds)
                ->get(['type_id', 'name', 'volume', 'packaged_volume'])
                ->keyBy('type_id');
        } catch (\Exception $e) {
            // Table may not exist yet; fall back to ESI per-item lookups
        }

        foreach ($buyRegionSellMin as $typeId => $buyPrice) {
            if (! isset($sellRegionBuyMax[$typeId])) {
                continue;
            }

            $sellPrice = $sellRegionBuyMax[$typeId];

            if ($sellPrice <= $buyPrice) {
                continue;
            }

            // Get volume and name from pre-loaded local SDE data, fall back to ESI
            $localType = $localTypes->get($typeId);
            if ($localType) {
                $volumeM3 = (float) ($localType->packaged_volume ?? $localType->volume ?? 0.0);
                $typeName = $localType->name;
            } else {
                $typeInfo = $this->esi->getTypeInfo($typeId);
                $volumeM3 = (float) ($typeInfo['packaged_volume'] ?? $typeInfo['volume'] ?? 0.0);
                $typeName = $typeInfo['name'] ?? "Type #{$typeId}";
            }

            $metrics = $this->calcArbitrageProfit(
                $buyPrice,
                $sellPrice,
                $brokerFeePercent,
                $salesTaxPercent,
                $transportIskPerM3,
                $volumeM3
            );

            if ($metrics['net_margin_pct'] < $minMarginPct || $metrics['net_profit_per_unit'] <= 0) {
                continue;
            }

            // Fetch historical volume in destination region for liquidity estimate
            $history = $this->esi->getMarketRegionHistory($sellRegionId, $typeId);
            $dailyVolume = 0;

            if ($history !== null) {
                $recent = collect($history)->sortByDesc('date')->take(30);
                $days   = $recent->count();
                $dailyVolume = $days > 0 ? (int) ($recent->sum('volume') / $days) : 0;

                // Only apply the volume floor when we have real history data.
                if ($dailyVolume < $minDailyVolume) {
                    continue;
                }
            }

            $opportunities[] = [
                'type_id'             => $typeId,
                'type_name'           => $typeName,
                'volume_m3'           => $volumeM3,
                'buy_price'           => $buyPrice,
                'sell_price'          => $sellPrice,
                'net_profit_per_unit' => $metrics['net_profit_per_unit'],
                'net_margin_pct'      => $metrics['net_margin_pct'],
                'roi_pct'             => $metrics['roi_pct'],
                'daily_volume'        => $dailyVolume,
                'daily_isk_profit'    => round($metrics['net_profit_per_unit'] * $dailyVolume, 0),
                'cargo_value_m3'      => $volumeM3 > 0 ? round($metrics['net_profit_per_unit'] / $volumeM3, 0) : null,
            ];
        }

        // Sort by daily ISK profit descending
        usort($opportunities, fn($a, $b) => $b['daily_isk_profit'] <=> $a['daily_isk_profit']);

        $elapsed = round(microtime(true) - $start, 2);
        Log::info("MarketTradingService: arbitrage scan from region {$buyRegionId} to {$sellRegionId} found ".count($opportunities)." opportunities in {$elapsed}s");

        return $limit === null ? $opportunities : array_slice($opportunities, 0, $limit);
    }

    /**
     * Resolve a type name, preferring local SDE data over ESI.
     */
    private function resolveTypeName(int $typeId): string
    {
        // Check local SDE data first (no API call needed)
        try {
            $local = EveType::find($typeId);
            if ($local) {
                return $local->name;
            }
        } catch (\Exception $e) {
            // Table may not exist yet; fall through to ESI
        }

        // Fall back to ESI
        $info = $this->esi->getTypeInfo($typeId);

        return $info['name'] ?? "Type #{$typeId}";
    }
}
