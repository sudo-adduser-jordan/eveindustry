<?php

namespace App\Http\Controllers\Api\V1\Industry;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Heavy\NoInputRequest;
use App\Http\Resources\V1\Industry\StockReportItemResource;
use App\Http\Responses\ApiResponse;
use App\Services\AssetService;
use App\Services\IndustryStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The industry stock manager report. Only for users with the Industry Stock
 * Manager enabled (403 otherwise). Items are changed through `/stock/items`.
 */
class StockReportController extends Controller
{
    /**
     * Stock report.
     *
     * The user's watched items by name, each with the wanted quantity, what
     * the corporation hangars hold and the shortfall. `meta.warning` says why
     * on-hand quantities are all 0 when corporation assets cannot be read.
     *
     * @response array{data: list<StockReportItemResource>, meta: array{warning: string|null}}
     */
    public function index(NoInputRequest $request, IndustryStockService $stock): JsonResponse
    {
        $report = $stock->getStockReport((int) $request->user()->id);

        return ApiResponse::success(
            StockReportItemResource::collection($report['items']),
            ['warning' => $report['warning']],
        );
    }

    /**
     * Refresh the stock report.
     *
     * Forgets the cached assets so the next report reads them again.
     */
    public function refresh(NoInputRequest $request, AssetService $assets): Response
    {
        $assets->clearUserCache((int) $request->user()->id);

        return response()->noContent();
    }
}
