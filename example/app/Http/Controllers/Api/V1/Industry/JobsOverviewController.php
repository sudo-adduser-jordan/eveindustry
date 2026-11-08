<?php

namespace App\Http\Controllers\Api\V1\Industry;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Industry\JobsOverviewRequest;
use App\Http\Resources\V1\Industry\JobsOverviewResource;
use App\Http\Responses\ApiResponse;
use App\Services\JobsOverviewService;
use App\Services\MarketFreshnessService;
use App\Services\MarketTradingService;
use Illuminate\Http\JsonResponse;

class JobsOverviewController extends Controller
{
    /**
     * Industry jobs overview.
     *
     * Active jobs across all the user's characters, grouped by activity, with
     * the manufacturing and reaction output totalled per item. Values use
     * the sell prices of the chosen trade hub, so `meta.freshness` is that
     * region's. Read from a cache that background jobs keep warm.
     *
     * @response array{data: JobsOverviewResource, meta: array{region: array{region_id: int, region_name: string}, freshness: array{prices_as_of: string|null, costs_as_of: string|null, source: string, stale: bool}}}
     */
    public function show(JobsOverviewRequest $request, JobsOverviewService $overview, MarketFreshnessService $freshness): JsonResponse
    {
        $regionId = $request->regionId();

        return ApiResponse::success(
            new JobsOverviewResource($overview->forUser((int) $request->user()->id, $regionId)),
            [
                'region' => ['region_id' => $regionId, 'region_name' => MarketTradingService::regionName($regionId)],
                'freshness' => $freshness->freshnessMeta($regionId),
            ],
        );
    }
}
