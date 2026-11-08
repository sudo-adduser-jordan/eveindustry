<?php

namespace App\Http\Controllers\Api\V1\Reference;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reference\SearchRequest;
use App\Http\Resources\V1\Reference\ActivityResource;
use App\Http\Resources\V1\Reference\TypeSummaryResource;
use App\Http\Responses\ApiResponse;
use App\Services\Reference\BlueprintLookupService;
use Illuminate\Http\JsonResponse;

class BlueprintController extends Controller
{
    public const SEARCH_LIMIT = 20;

    /**
     * Search blueprints.
     *
     * Published blueprints whose name contains `q`, by name, at most 20
     * (not paginated). Falls back to ESI search when the SDE has no match.
     *
     * @response array{data: list<TypeSummaryResource>, meta: array{limit: int}}
     */
    public function index(SearchRequest $request, BlueprintLookupService $blueprints): JsonResponse
    {
        return ApiResponse::success(
            TypeSummaryResource::collection($blueprints->searchBlueprints($request->term(), self::SEARCH_LIMIT)),
            ['limit' => self::SEARCH_LIMIT],
        );
    }

    /**
     * Blueprint manufacturing activity.
     *
     * Materials, products and base time for one manufacturing run.
     *
     * @response array{data: ActivityResource, meta: object}
     */
    public function show(int $blueprintTypeId, BlueprintLookupService $blueprints): JsonResponse
    {
        $activity = $blueprints->manufacturingActivity($blueprintTypeId);
        abort_if($activity === null, 404, 'Blueprint not found.');

        return ApiResponse::success(new ActivityResource(
            ['type_id' => $blueprintTypeId, 'activity' => 'manufacturing'] + $activity,
        ));
    }
}
