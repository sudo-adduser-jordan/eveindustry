<?php

namespace App\Http\Controllers\Api\V1\Industry;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Heavy\NoInputRequest;
use App\Http\Resources\V1\Industry\CanProduceSourceResource;
use App\Http\Responses\ApiResponse;
use App\Services\ProductionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class CanProduceController extends Controller
{
    /**
     * What you can produce now.
     *
     * Per character, and per corporation where the character is a director or
     * station manager: the blueprints they hold, cross-referenced with the
     * materials in their hangars, giving the runs and output each item allows.
     * Cached for an hour; `POST /industry/can-produce/refresh` recomputes it.
     *
     * @response array{data: list<CanProduceSourceResource>, meta: object}
     */
    public function index(NoInputRequest $request, ProductionService $production): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $sources = Cache::remember(
            "can_produce.{$userId}",
            3600,
            fn () => $production->getCanProduceForUser($userId),
        );

        return ApiResponse::success(CanProduceSourceResource::collection($sources));
    }
}
