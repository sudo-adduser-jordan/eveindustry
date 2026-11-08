<?php

namespace App\Http\Controllers\Api\V1\Planetary;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Heavy\NoInputRequest;
use App\Http\Resources\V1\Planetary\PlanetaryDashboardResource;
use App\Http\Responses\ApiResponse;
use App\Services\PlanetaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PlanetaryController extends Controller
{
    /**
     * Planetary interaction dashboard.
     *
     * Colonies of every character with extractors, factories, storage fill,
     * combined output per hour, alerts and a factory capacity check. Read
     * from a cache that is filled from ESI on first use (a cold cache can be
     * slow); no prices are involved, so there is no `meta.freshness`.
     *
     * @response array{data: PlanetaryDashboardResource}
     */
    public function show(NoInputRequest $request, PlanetaryService $planetary): JsonResponse
    {
        $data = $planetary->getPlanetaryDataForUser((int) $request->user()->id);
        $data['capacity'] = $planetary->buildCapacityAnalysis($data);

        return ApiResponse::success(new PlanetaryDashboardResource($data));
    }

    /**
     * Forget the cached planetary data.
     *
     * The next dashboard request reads ESI again.
     */
    public function refresh(NoInputRequest $request, PlanetaryService $planetary): Response
    {
        $planetary->clearUserCache((int) $request->user()->id);

        return response()->noContent();
    }
}
