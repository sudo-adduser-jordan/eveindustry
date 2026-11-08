<?php

namespace App\Http\Controllers\Api\V1\Heavy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Heavy\NoInputRequest;
use App\Http\Resources\V1\Heavy\IndustryAvailabilityResource;
use App\Http\Resources\V1\Heavy\ReprocessingResource;
use App\Http\Responses\ApiResponse;
use App\Services\Heavy\ReprocessingSummaryService;
use App\Services\IndustryService;
use App\Services\MarketFreshnessService;
use App\Services\MarketService;
use Illuminate\Http\JsonResponse;

class IndustryController extends Controller
{
    /**
     * Industry slot availability.
     *
     * Manufacturing, research and reaction slots per character, with the
     * running jobs, and the jobs ready for collection (ending within a
     * minute). Slots are read from a cache that background jobs keep warm;
     * `meta.cached_until` is when the cache may next refresh. No prices are
     * involved, so there is no `meta.freshness`.
     *
     * @response array{data: IndustryAvailabilityResource, meta: array{cached_until: string}}
     */
    public function availability(NoInputRequest $request, IndustryService $industry): JsonResponse
    {
        $userId = (int) $request->user()->id;

        return ApiResponse::success(
            new IndustryAvailabilityResource($industry->getAvailabilityForUser($userId) + [
                'ready_for_collection' => $industry->getReadyForCollectionJobsForUser($userId, 60),
            ]),
            ['cached_until' => now()->addMinutes(20)->toIso8601String()],
        );
    }

    /**
     * Reprocessing opportunities.
     *
     * Items in the user's assets worth more reprocessed than sold, per
     * character and location, with totals. Prices are the global market
     * prices, so `meta.freshness` is The Forge's.
     *
     * @response array{data: ReprocessingResource, meta: array{freshness: array{prices_as_of: ?string, costs_as_of: ?string, source: string, stale: bool}}}
     */
    public function reprocessing(NoInputRequest $request, ReprocessingSummaryService $summary, MarketFreshnessService $freshness): JsonResponse
    {
        return ApiResponse::success(
            new ReprocessingResource($summary->forUser((int) $request->user()->id)),
            ['freshness' => $freshness->freshnessMeta(MarketService::DEFAULT_REGION_ID)],
        );
    }
}
