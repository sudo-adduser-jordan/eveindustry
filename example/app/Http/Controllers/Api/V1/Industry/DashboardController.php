<?php

namespace App\Http\Controllers\Api\V1\Industry;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Heavy\NoInputRequest;
use App\Http\Resources\V1\Industry\DashboardResource;
use App\Http\Responses\ApiResponse;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Dashboard.
     *
     * Industry job and slot counts and planetary alerts (read from caches that
     * `dashboard:refresh` warms every 15 minutes, computed on the spot when
     * cold), the user's characters with their last known location and training,
     * and their saved reaction and invention plans.
     *
     * @response array{data: DashboardResource, meta: object}
     */
    public function show(NoInputRequest $request, DashboardService $dashboard): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success(new DashboardResource([
            'user' => $user,
            'industry' => $dashboard->industryStats((int) $user->id),
            'planetary' => $dashboard->planetaryStats((int) $user->id),
            'characters' => $user->characters()->orderByDesc('is_main')->orderBy('name')->get(),
            'reaction_plans' => $user->reactionPlans()->get(),
            'invention_plans' => $user->inventionPlans()->get(),
        ]));
    }
}
