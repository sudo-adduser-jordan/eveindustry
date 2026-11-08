<?php

namespace App\Http\Controllers\Api\V1\Planning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Planning\PageRequest;
use App\Http\Requests\Api\V1\Planning\ReactionPlanStoreRequest;
use App\Http\Requests\Api\V1\Planning\ReactionPlanUpdateRequest;
use App\Http\Resources\V1\Planning\ReactionPlanResource;
use App\Http\Responses\ApiResponse;
use App\Models\ReactionPlan;
use App\Services\Planning\PlanStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Saved reaction planner setups. Private to their owner: another user's plan
 * answers 404 `not_found`.
 */
class ReactionPlanController extends Controller
{
    public function __construct(private PlanStore $plans) {}

    /**
     * List the user's reaction plans.
     *
     * Sorted by name.
     *
     * @response array{data: list<ReactionPlanResource>, meta: array{pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function index(PageRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            $this->plans->paginateReaction($request->user(), $request->page(), $request->perPage()),
            ReactionPlanResource::class,
        );
    }

    /**
     * Save a reaction plan.
     *
     * Omitted settings fall back to defaults (240 runs per slot, athanor, no
     * rigs, lowsec, 0 tax).
     *
     * @response array{data: ReactionPlanResource, meta: object}
     */
    public function store(ReactionPlanStoreRequest $request): JsonResponse
    {
        Gate::authorize('create', ReactionPlan::class);

        return ApiResponse::success(
            ReactionPlanResource::make($this->plans->createReaction($request->user(), $request->validated())),
            [],
            201,
        );
    }

    /**
     * Show one reaction plan.
     *
     * @response array{data: ReactionPlanResource, meta: object}
     */
    public function show(ReactionPlan $plan): JsonResponse
    {
        Gate::authorize('view', $plan);

        return ApiResponse::success(ReactionPlanResource::make($plan));
    }

    /**
     * Update a reaction plan. Partial: only the fields sent change.
     *
     * @response array{data: ReactionPlanResource, meta: object}
     */
    public function update(ReactionPlanUpdateRequest $request, ReactionPlan $plan): JsonResponse
    {
        Gate::authorize('update', $plan);

        return ApiResponse::success(
            ReactionPlanResource::make($this->plans->updateReaction($plan, $request->validated()))
        );
    }

    /**
     * Delete a reaction plan.
     */
    public function destroy(ReactionPlan $plan): Response
    {
        Gate::authorize('delete', $plan);

        $this->plans->delete($plan);

        return response()->noContent();
    }
}
