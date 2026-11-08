<?php

namespace App\Http\Controllers\Api\V1\Calculators;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Calculators\AssetStockRequest;
use App\Http\Requests\Api\V1\Calculators\FormulaStockRequest;
use App\Http\Requests\Api\V1\Calculators\ReactionPlannerCalculateRequest;
use App\Http\Resources\V1\Calculators\ReactionPlanCalculationResource;
use App\Http\Resources\V1\Calculators\StockLevelResource;
use App\Http\Responses\ApiResponse;
use App\Services\Calculators\CharacterStockService;
use App\Services\Calculators\ReactionPlanCalculator;
use App\Services\MarketFreshnessService;
use Illuminate\Http\JsonResponse;

class ReactionPlannerController extends Controller
{
    /**
     * Calculate a reaction plan.
     *
     * Resolves each composite's chain into composite and intermediate slots
     * and raw materials, then prices it: input cost, output value, job fee,
     * facility tax, SCC fee, profit and margin. Unknown formulas are
     * skipped.
     *
     * @response array{data: ReactionPlanCalculationResource, meta: array{freshness: array{prices_as_of: string|null, costs_as_of: string|null, source: string, stale: bool}}}
     */
    public function calculate(ReactionPlannerCalculateRequest $request, ReactionPlanCalculator $plans, MarketFreshnessService $freshness): JsonResponse
    {
        return ApiResponse::success(new ReactionPlanCalculationResource($plans->calculate($request->validated())), [
            'freshness' => $freshness->freshnessMeta($request->regionId()),
        ]);
    }

    /**
     * Owned quantity of each requested type.
     *
     * Summed over your characters with the assets scope; one row per
     * requested type id (0 when none is owned).
     *
     * @response array{data: list<StockLevelResource>, meta: object}
     */
    public function stock(AssetStockRequest $request, CharacterStockService $stock): JsonResponse
    {
        return ApiResponse::success(StockLevelResource::collection(
            $stock->levels($request->validated('type_ids'), $stock->assetStock($request->user(), $request->validated('type_ids')))
        ));
    }

    /**
     * Owned reaction formulas.
     *
     * One row per requested formula type id (type_id, quantity), counting
     * formulas in your characters' blueprints and in active reaction jobs.
     * When none of your characters has the blueprints scope,
     * has_blueprint_scope is false and formulas is empty.
     *
     * @response array{data: array{has_blueprint_scope: bool, formulas: list<StockLevelResource>}, meta: object}
     */
    public function formulaStock(FormulaStockRequest $request, CharacterStockService $stock): JsonResponse
    {
        $result = $stock->formulaStock($request->user(), $request->validated('formula_type_ids'));

        $formulas = $result['has_blueprint_scope']
            ? $stock->levels($request->validated('formula_type_ids'), $result['stock'])
            : [];

        return ApiResponse::success([
            'has_blueprint_scope' => $result['has_blueprint_scope'],
            'formulas' => StockLevelResource::collection($formulas)->resolve($request),
        ]);
    }
}
