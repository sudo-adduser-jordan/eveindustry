<?php

namespace App\Http\Controllers\Api\V1\Reference;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reference\SearchRequest;
use App\Http\Resources\V1\Reference\CostIndicesResource;
use App\Http\Resources\V1\Reference\SolarSystemResource;
use App\Http\Responses\ApiResponse;
use App\Services\Reference\SolarSystemLookupService;
use App\Services\Reference\SystemCostIndexService;
use Illuminate\Http\JsonResponse;

class SolarSystemController extends Controller
{
    /**
     * Search solar systems.
     *
     * Systems whose name contains `q` (case-insensitive substring), by name,
     * at most 20 (not paginated). SDE only; security is unrounded.
     *
     * @response array{data: list<SolarSystemResource>, meta: array{limit: int}}
     */
    public function index(SearchRequest $request, SolarSystemLookupService $systems): JsonResponse
    {
        return ApiResponse::success(
            SolarSystemResource::collection($systems->search($request->term())),
            ['limit' => SolarSystemLookupService::SEARCH_LIMIT],
        );
    }

    /**
     * Solar system.
     *
     * @response array{data: SolarSystemResource, meta: object}
     */
    public function show(int $systemId, SolarSystemLookupService $systems): JsonResponse
    {
        $system = $systems->find($systemId);
        abort_if($system === null, 404, 'Solar system not found.');

        return ApiResponse::success(new SolarSystemResource($system));
    }

    /**
     * Industry cost indices.
     *
     * ESI cost index per industry activity for the system (ESI data cached up
     * to 6 h); 0 for activities ESI does not list. 404 when the system is
     * neither in the SDE nor in ESI's list; 503 when ESI is unavailable.
     *
     * @response array{data: CostIndicesResource, meta: object}
     */
    public function costIndices(int $systemId, SolarSystemLookupService $systems, SystemCostIndexService $costIndices): JsonResponse
    {
        $indices = $costIndices->forSystem($systemId);
        abort_if($indices === null, 503, 'Cost indices are unavailable from ESI right now.');
        abort_if($indices === [] && $systems->find($systemId) === null, 404, 'Solar system not found.');

        return ApiResponse::success(new CostIndicesResource(['solar_system_id' => $systemId, 'indices' => $indices]));
    }
}
