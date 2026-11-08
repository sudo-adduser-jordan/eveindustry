<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\RegionFreshnessResource;
use App\Http\Responses\ApiResponse;
use App\Services\MarketFreshnessService;
use Illuminate\Http\JsonResponse;

class MarketFreshnessController extends Controller
{
    public function __construct(private MarketFreshnessService $freshness) {}

    /**
     * Market data freshness.
     *
     * Per trade region: when orders, precomputed manufacturing costs and ESI
     * global prices were last refreshed, and whether any of them is stale.
     * Orders are "as of" the start of the region's last complete order sweep.
     * Stale thresholds: orders 2 h, costs 5 h, global prices 26 h.
     *
     * @response array{data: list<RegionFreshnessResource>, meta: array{stale_after_hours: array{orders: int, costs: int, global: int}}}
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            RegionFreshnessResource::collection(array_values($this->freshness->regions())),
            ['stale_after_hours' => [
                'orders' => MarketFreshnessService::ORDERS_STALE_AFTER_HOURS,
                'costs' => MarketFreshnessService::COSTS_STALE_AFTER_HOURS,
                'global' => MarketFreshnessService::GLOBAL_STALE_AFTER_HOURS,
            ]],
        );
    }
}
