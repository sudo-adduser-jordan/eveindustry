<?php

namespace App\Http\Controllers\Api\V1\Reference;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reference\StationSearchRequest;
use App\Http\Requests\Api\V1\Reference\SystemStationsRequest;
use App\Http\Resources\V1\Reference\StationResource;
use App\Http\Responses\ApiResponse;
use App\Services\Reference\SolarSystemLookupService;
use App\Services\Reference\StationLookupService;
use Illuminate\Http\JsonResponse;

class StationController extends Controller
{
    /**
     * Search NPC stations.
     *
     * Stations whose name contains `q`, by name, at most 20 (not paginated),
     * optionally only in `solar_system_id`.
     *
     * @response array{data: list<StationResource>, meta: array{limit: int}}
     */
    public function index(StationSearchRequest $request, StationLookupService $stations): JsonResponse
    {
        return ApiResponse::success(
            StationResource::collection($stations->search($request->term(), $request->solarSystemId())),
            ['limit' => StationLookupService::SEARCH_LIMIT],
        );
    }

    /**
     * NPC station.
     *
     * @response array{data: StationResource, meta: object}
     */
    public function show(int $stationId, StationLookupService $stations): JsonResponse
    {
        $station = $stations->find($stationId);
        abort_if($station === null, 404, 'Station not found.');

        return ApiResponse::success(new StationResource($station));
    }

    /**
     * Stations in a solar system.
     *
     * Every NPC station in the system, by name, paginated; `q` (2+ characters)
     * narrows by name. 404 when the system is not in the SDE.
     *
     * @response array{data: list<StationResource>, meta: array{pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function inSystem(int $systemId, SystemStationsRequest $request, SolarSystemLookupService $systems, StationLookupService $stations): JsonResponse
    {
        abort_if($systems->find($systemId) === null, 404, 'Solar system not found.');

        return ApiResponse::paginatedArray(
            $stations->inSystem($systemId, $request->term())->all(),
            $request->page(),
            $request->perPage(),
            StationResource::class,
        );
    }
}
