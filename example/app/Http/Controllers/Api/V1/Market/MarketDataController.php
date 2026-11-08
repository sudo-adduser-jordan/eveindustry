<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Market\HistoryRequest;
use App\Http\Requests\Api\V1\Market\OrdersRequest;
use App\Http\Requests\Api\V1\Market\PricesRequest;
use App\Http\Resources\V1\Market\MarketHistoryResource;
use App\Http\Resources\V1\Market\MarketOrderResource;
use App\Http\Resources\V1\Market\MarketPriceResource;
use App\Http\Responses\ApiResponse;
use App\Models\EveType;
use App\Services\MarketFreshnessService;
use App\Services\MarketService;
use Illuminate\Http\JsonResponse;

class MarketDataController extends Controller
{
    public function __construct(
        private MarketService $market,
        private MarketFreshnessService $freshness,
    ) {}

    /**
     * Sell prices for up to 200 types.
     *
     * The same price the calculators use: the volume-weighted average of the
     * cheapest 5% of sell orders in the region (`source: orders`), else the
     * stored market_prices value (`fallback`), else 0 (`none`). Items come
     * back in request order, duplicates removed. Not paginated.
     *
     * @response array{data: list<MarketPriceResource>, meta: array{region_id: int, freshness: array{prices_as_of: string|null, costs_as_of: string|null, source: string, stale: bool}}}
     */
    public function prices(PricesRequest $request): JsonResponse
    {
        $regionId = $request->regionId();
        $prices = $this->market->getSellPricesWithSource($request->typeIds(), $regionId);

        $rows = [];
        foreach ($request->typeIds() as $typeId) {
            $entry = $prices[$typeId] ?? ['price' => 0.0, 'source' => MarketService::PRICE_SOURCE_NONE];
            $rows[] = ['type_id' => $typeId] + $entry;
        }

        return ApiResponse::success(MarketPriceResource::collection($rows), [
            'region_id' => $regionId,
            'freshness' => $this->freshness->freshnessMeta($regionId),
        ]);
    }

    /**
     * Daily market history for a type.
     *
     * The most recent `days` daily rows (default 30, max 365) from the
     * everef history import, newest first, paginated. 404 when the type is
     * unknown; an empty list when it has no history in the region.
     *
     * @response array{data: list<MarketHistoryResource>, meta: array{type_id: int, region_id: int, freshness: array{prices_as_of: string|null, costs_as_of: string|null, source: string, stale: bool}, pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function history(HistoryRequest $request, int $typeId): JsonResponse
    {
        abort_unless(EveType::where('type_id', $typeId)->exists(), 404, 'Type not found.');

        $regionId = $request->regionId();
        $rows = $this->market->getHistory($typeId, $regionId, $request->days());

        return ApiResponse::paginatedArray($rows, $request->page(), $request->perPage(), MarketHistoryResource::class, [
            'type_id' => $typeId,
            'region_id' => $regionId,
            'freshness' => $this->freshness->freshnessMeta($regionId),
        ]);
    }

    /**
     * Current orders for a type.
     *
     * Open orders (volume remaining > 0) for one type in one trade hub
     * region, best first: sell orders cheapest first, buy orders highest
     * first. Paginated.
     *
     * @response array{data: list<MarketOrderResource>, meta: array{type_id: int, region_id: int, side: string, freshness: array{prices_as_of: string|null, costs_as_of: string|null, source: string, stale: bool}, pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function orders(OrdersRequest $request): JsonResponse
    {
        $regionId = $request->regionId();
        $paginator = $this->market
            ->ordersQuery($request->typeId(), $regionId, $request->side())
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        return ApiResponse::paginated($paginator, MarketOrderResource::class, [
            'type_id' => $request->typeId(),
            'region_id' => $regionId,
            'side' => $request->side(),
            'freshness' => $this->freshness->freshnessMeta($regionId),
        ]);
    }
}
