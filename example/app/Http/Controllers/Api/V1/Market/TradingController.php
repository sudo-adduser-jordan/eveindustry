<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Market\RegionArbitrageRequest;
use App\Http\Requests\Api\V1\Market\StationTradingRequest;
use App\Http\Resources\V1\Market\ArbitrageOpportunityResource;
use App\Http\Resources\V1\Market\StationTradingOpportunityResource;
use App\Http\Responses\ApiResponse;
use App\Services\MarketFreshnessService;
use App\Services\MarketTradingService;
use Illuminate\Http\JsonResponse;

class TradingController extends Controller
{
    public function __construct(
        private MarketTradingService $trading,
        private MarketFreshnessService $freshness,
    ) {}

    /**
     * Station trading opportunities.
     *
     * Buy-order / sell-order spreads in one trade hub region, after broker
     * fees and sales tax, sorted by estimated daily ISK profit. The full
     * result is paginated (the old route capped it with `limit` instead).
     *
     * @response array{data: list<StationTradingOpportunityResource>, meta: array{region: array{region_id: int, region_name: string}, freshness: array{prices_as_of: string|null, costs_as_of: string|null, source: string, stale: bool}, pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function stationTrading(StationTradingRequest $request): JsonResponse
    {
        $regionId = $request->regionId();

        $results = $this->trading->scanStationTrading(
            $regionId,
            $request->brokerFee(),
            $request->salesTax(),
            $request->minMargin(),
            $request->minDailyVolume(),
            null,
        );

        return ApiResponse::paginatedArray($results, $request->page(), $request->perPage(), StationTradingOpportunityResource::class, [
            'region' => ['region_id' => $regionId, 'region_name' => MarketTradingService::regionName($regionId)],
            'freshness' => $this->freshness->freshnessMeta($regionId),
        ]);
    }

    /**
     * Region arbitrage opportunities.
     *
     * Buy from sell orders in one trade hub and sell into buy orders in
     * another, after sales tax and optional hauling cost per m3. Sorted by
     * estimated daily ISK profit and paginated.
     *
     * `meta.freshness` describes the buy (source) region; `meta.freshness_by_region`
     * has the same block for both regions, keyed by region id.
     *
     * @response array{data: list<ArbitrageOpportunityResource>, meta: array{buy_region: array{region_id: int, region_name: string}, sell_region: array{region_id: int, region_name: string}, freshness: array{prices_as_of: string|null, costs_as_of: string|null, source: string, stale: bool}, freshness_by_region: array<string, array{prices_as_of: string|null, costs_as_of: string|null, source: string, stale: bool}>, pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function regionArbitrage(RegionArbitrageRequest $request): JsonResponse
    {
        $buyRegionId = $request->buyRegionId();
        $sellRegionId = $request->sellRegionId();

        $results = $this->trading->scanRegionArbitrage(
            $buyRegionId,
            $sellRegionId,
            $request->brokerFee(),
            $request->salesTax(),
            $request->transportIskPerM3(),
            $request->minMargin(),
            $request->minDailyVolume(),
            null,
        );

        return ApiResponse::paginatedArray($results, $request->page(), $request->perPage(), ArbitrageOpportunityResource::class, [
            'buy_region' => ['region_id' => $buyRegionId, 'region_name' => MarketTradingService::regionName($buyRegionId)],
            'sell_region' => ['region_id' => $sellRegionId, 'region_name' => MarketTradingService::regionName($sellRegionId)],
            'freshness' => $this->freshness->freshnessMeta($buyRegionId),
            'freshness_by_region' => (object) $this->freshness->freshnessMetaByRegion([$buyRegionId, $sellRegionId]),
        ]);
    }
}
