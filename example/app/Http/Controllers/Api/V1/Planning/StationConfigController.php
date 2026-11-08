<?php

namespace App\Http\Controllers\Api\V1\Planning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Planning\PageRequest;
use App\Http\Requests\Api\V1\Planning\StationConfigStoreRequest;
use App\Http\Requests\Api\V1\Planning\StationConfigUpdateRequest;
use App\Http\Resources\V1\Planning\StationConfigResource;
use App\Http\Responses\ApiResponse;
use App\Models\StationConfig;
use App\Services\Planning\StationConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Manufacturing station configurations. Private to their owner: another
 * user's config answers 404 `not_found`.
 */
class StationConfigController extends Controller
{
    public function __construct(private StationConfigService $configs) {}

    /**
     * List the user's station configs.
     *
     * Default first, then by name.
     *
     * @response array{data: list<StationConfigResource>, meta: array{pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function index(PageRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            $this->configs->paginate($request->user(), $request->page(), $request->perPage()),
            StationConfigResource::class,
        );
    }

    /**
     * Save a station config.
     *
     * With `is_default` true it becomes the only default.
     */
    public function store(StationConfigStoreRequest $request): JsonResponse
    {
        Gate::authorize('create', StationConfig::class);

        return ApiResponse::success(
            StationConfigResource::make($this->configs->create($request->user(), $request->validated())),
            [],
            201,
        );
    }

    /**
     * Update a station config. Partial: only the fields sent change.
     */
    public function update(StationConfigUpdateRequest $request, StationConfig $stationConfig): JsonResponse
    {
        Gate::authorize('update', $stationConfig);

        return ApiResponse::success(
            StationConfigResource::make($this->configs->update($request->user(), $stationConfig, $request->validated()))
        );
    }

    /**
     * Delete a station config.
     */
    public function destroy(StationConfig $stationConfig): Response
    {
        Gate::authorize('delete', $stationConfig);

        $this->configs->delete($stationConfig);

        return response()->noContent();
    }

    /**
     * Make a station config the default.
     *
     * Clears the flag on the user's other configs for the same activity.
     */
    public function setDefault(StationConfig $stationConfig): JsonResponse
    {
        Gate::authorize('update', $stationConfig);

        return ApiResponse::success(
            StationConfigResource::make($this->configs->setDefault(request()->user(), $stationConfig))
        );
    }
}
