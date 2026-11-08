<?php

namespace App\Http\Controllers\Api\V1\Reference;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reference\SearchRequest;
use App\Http\Resources\V1\Reference\ActivityResource;
use App\Http\Resources\V1\Reference\TypeSummaryResource;
use App\Http\Responses\ApiResponse;
use App\Services\Reference\BlueprintLookupService;
use Illuminate\Http\JsonResponse;

class ReactionFormulaController extends Controller
{
    public const SEARCH_LIMIT = 20;

    /**
     * Search reaction formulas.
     *
     * Published reaction formulas whose name contains `q`, by name, at most
     * 20 (not paginated). Serves both the reactions calculator and the
     * reaction planner.
     *
     * @response array{data: list<TypeSummaryResource>, meta: array{limit: int}}
     */
    public function index(SearchRequest $request, BlueprintLookupService $blueprints): JsonResponse
    {
        return ApiResponse::success(
            TypeSummaryResource::collection($blueprints->searchReactionFormulas($request->term(), self::SEARCH_LIMIT)),
            ['limit' => self::SEARCH_LIMIT],
        );
    }

    /**
     * Reaction formula activity.
     *
     * Input materials, products and base time for one reaction run.
     *
     * @response array{data: ActivityResource, meta: object}
     */
    public function show(int $formulaTypeId, BlueprintLookupService $blueprints): JsonResponse
    {
        $activity = $blueprints->reactionActivity($formulaTypeId);
        abort_if($activity === null, 404, 'Reaction formula not found.');

        return ApiResponse::success(new ActivityResource(
            ['type_id' => $formulaTypeId, 'activity' => 'reaction'] + $activity,
        ));
    }
}
