<?php

namespace App\Http\Controllers\Api\V1\Calculators;

use App\Http\Controllers\Api\V1\Calculators\Concerns\HandlesCalculatorErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Calculators\AssetStockRequest;
use App\Http\Requests\Api\V1\Calculators\CharacterSkillsRequest;
use App\Http\Requests\Api\V1\Calculators\ManufacturingCalculateRequest;
use App\Http\Requests\Api\V1\Calculators\QueueSummaryRequest;
use App\Http\Resources\V1\Calculators\CharacterSkillsResource;
use App\Http\Resources\V1\Calculators\ManufacturingJobResource;
use App\Http\Resources\V1\Calculators\QueueSummaryResource;
use App\Http\Resources\V1\Calculators\StockLevelResource;
use App\Http\Responses\ApiResponse;
use App\Services\Calculators\CharacterSkillsService;
use App\Services\Calculators\CharacterStockService;
use App\Services\Calculators\ManufacturingJobCalculator;
use App\Services\IndustryProfitCalculator;
use App\Services\MarketFreshnessService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ManufacturingController extends Controller
{
    use HandlesCalculatorErrors;

    /**
     * Calculate a manufacturing job.
     *
     * Materials (with buildable components at ME 10), build time, job fees
     * (EIV x system cost index + facility tax, when solar_system_id and/or
     * reaction_solar_system_id are given) and the full-buy / full-build
     * profit scenarios. Materials, base time and portion size come from the
     * SDE when not posted. A blueprint with no materials is a 422 on
     * blueprint_type_id.
     *
     * @response array{data: ManufacturingJobResource, meta: array{freshness: array{prices_as_of: string|null, costs_as_of: string|null, source: string, stale: bool}}}
     */
    public function calculate(ManufacturingCalculateRequest $request, ManufacturingJobCalculator $jobs, MarketFreshnessService $freshness): JsonResponse
    {
        $result = $this->calculateOr422(fn () => $jobs->calculate($request->validated()));

        return ApiResponse::success(new ManufacturingJobResource($result), [
            'freshness' => $freshness->freshnessMeta($request->regionId()),
        ]);
    }

    /**
     * Build queue summary.
     *
     * Profit and margin per job and in total, from each job's revenue,
     * input cost and fees as returned by the calculate endpoint.
     *
     * @response array{data: QueueSummaryResource, meta: object}
     */
    public function queueSummary(QueueSummaryRequest $request): JsonResponse
    {
        return ApiResponse::success(new QueueSummaryResource(IndustryProfitCalculator::totals($request->validated('items'))));
    }

    /**
     * Manufacturing skills of one of your characters.
     *
     * Industry and Advanced Industry levels. 404 when the character is not
     * one of yours.
     *
     * @response array{data: array{character_id: int, character_name: string, skills: array{industry: int, advanced_industry: int}}, meta: object}
     */
    public function skills(CharacterSkillsRequest $request, CharacterSkillsService $skills): JsonResponse
    {
        $character = $skills->findForUser((int) $request->user()->id, $request->characterId())
            ?? throw new NotFoundHttpException('Character not found.');

        return ApiResponse::success(new CharacterSkillsResource([
            'character' => $character,
            'skills' => $skills->manufacturing($character),
        ]));
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
}
