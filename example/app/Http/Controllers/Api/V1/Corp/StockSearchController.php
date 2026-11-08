<?php

namespace App\Http\Controllers\Api\V1\Corp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Corp\StockSearchRequest;
use App\Http\Resources\V1\Corp\StockTypeResource;
use App\Http\Responses\ApiResponse;
use App\Services\IndustryStockService;
use Illuminate\Http\JsonResponse;

class StockSearchController extends Controller
{
    public function __construct(private IndustryStockService $stock) {}

    /**
     * Stock manager type search.
     *
     * Published types whose name contains `q`, by name, at most
     * `meta.limit` (20) results. Only for users with the Industry Stock
     * Manager enabled; otherwise 403.
     *
     * @response array{data: list<StockTypeResource>, meta: array{limit: int}}
     */
    public function index(StockSearchRequest $request): JsonResponse
    {
        return ApiResponse::success(
            StockTypeResource::collection($this->stock->searchTypes($request->term())),
            ['limit' => IndustryStockService::SEARCH_LIMIT],
        );
    }
}
