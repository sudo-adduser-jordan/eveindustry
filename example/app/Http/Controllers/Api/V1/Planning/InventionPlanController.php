<?php

namespace App\Http\Controllers\Api\V1\Planning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Planning\InventionPlanStoreRequest;
use App\Http\Requests\Api\V1\Planning\InventionPlanUpdateRequest;
use App\Http\Requests\Api\V1\Planning\PageRequest;
use App\Http\Resources\V1\Planning\InventionPlanResource;
use App\Http\Responses\ApiResponse;
use App\Models\InventionPlan;
use App\Services\Planning\PlanStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Saved invention planner setups. Private to their owner: another user's plan
 * answers 404 `not_found`.
 */
class InventionPlanController extends Controller
{
    public function __construct(private PlanStore $plans) {}

    /**
     * List the user's invention plans.
     *
     * Sorted by name.
     *
     * @response array{data: list<InventionPlanResource>, meta: array{pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function index(PageRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            $this->plans->paginateInvention($request->user(), $request->page(), $request->perPage()),
            InventionPlanResource::class,
        );
    }

    /**
     * Save an invention plan.
     *
     * Omitted settings fall back to defaults (no decryptor, no skills, one
     * run, no structure, highsec, 0 tax).
     *
     * @response array{data: InventionPlanResource, meta: object}
     */
    public function store(InventionPlanStoreRequest $request): JsonResponse
    {
        Gate::authorize('create', InventionPlan::class);

        return ApiResponse::success(
            InventionPlanResource::make($this->plans->createInvention($request->user(), $request->validated())),
            [],
            201,
        );
    }

    /**
     * Show one invention plan.
     *
     * @response array{data: InventionPlanResource, meta: object}
     */
    public function show(InventionPlan $plan): JsonResponse
    {
        Gate::authorize('view', $plan);

        return ApiResponse::success(InventionPlanResource::make($plan));
    }

    /**
     * Update an invention plan. Partial: only the fields sent change.
     *
     * @response array{data: InventionPlanResource, meta: object}
     */
    public function update(InventionPlanUpdateRequest $request, InventionPlan $plan): JsonResponse
    {
        Gate::authorize('update', $plan);

        return ApiResponse::success(
            InventionPlanResource::make($this->plans->updateInvention($plan, $request->validated()))
        );
    }

    /**
     * Delete an invention plan.
     */
    public function destroy(InventionPlan $plan): Response
    {
        Gate::authorize('delete', $plan);

        $this->plans->delete($plan);

        return response()->noContent();
    }
}
