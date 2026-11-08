<?php

namespace App\Http\Controllers\Api\V1\Calculators;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Calculators\FactoryPlannerRequest;
use App\Http\Resources\V1\Calculators\FactoryPlanResource;
use App\Http\Responses\ApiResponse;
use App\Services\Calculators\FactoryPlannerCalculator;
use App\Services\MarketFreshnessService;
use Illuminate\Http\JsonResponse;

class FactoryPlannerController extends Controller
{
    /**
     * Calculate factory planet profitability.
     *
     * Daily input cost, output value and profit per planet for every
     * schematic in a tier, after customs tax. The list is the tier's fixed
     * set of schematics, so it is not paginated.
     */
    public function calculate(FactoryPlannerRequest $request, FactoryPlannerCalculator $planner, MarketFreshnessService $freshness): JsonResponse
    {
        $result = $planner->calculate($request->tier(), $request->regionId(), $request->taxRate());

        return ApiResponse::success(new FactoryPlanResource($result), [
            'freshness' => $freshness->freshnessMeta($request->regionId()),
        ]);
    }
}
