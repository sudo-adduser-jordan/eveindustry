<?php

namespace App\Http\Controllers\Api\V1\Heavy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Heavy\ListRequest;
use App\Http\Requests\Api\V1\Heavy\ProgressRequest;
use App\Http\Resources\V1\Heavy\AssetLocationResource;
use App\Http\Resources\V1\Heavy\BuildProgramProgressResource;
use App\Http\Responses\ApiResponse;
use App\Models\BuildProgram;
use App\Services\Heavy\HeavyServiceException;
use App\Services\Heavy\PersonalBuildProgramProgressService;
use Illuminate\Http\JsonResponse;

class BuildProgramProgressController extends Controller
{
    public function __construct(private PersonalBuildProgramProgressService $service) {}

    /**
     * Build program progress.
     *
     * How much of the program the user already holds, counting the assets of
     * all their characters (optionally only at some stations/structures).
     * Someone else's program is 404; a user with no linked characters gets 400.
     *
     * @response array{data: BuildProgramProgressResource, meta: array<string, mixed>}
     */
    public function progress(ProgressRequest $request, BuildProgram $program): JsonResponse
    {
        try {
            $result = $this->service->progress($request->user(), $program, $request->locationIds());
        } catch (HeavyServiceException $e) {
            abort($e->status, $e->getMessage());
        }

        return ApiResponse::success(new BuildProgramProgressResource($result));
    }

    /**
     * Build program asset locations.
     *
     * Stations and structures where the user's characters hold items, by
     * solar system then name, for the progress location filter. Someone
     * else's program is 404.
     *
     * @response array{data: list<AssetLocationResource>, meta: array{pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function assetLocations(ListRequest $request, BuildProgram $program): JsonResponse
    {
        return ApiResponse::paginatedArray(
            $this->service->assetLocations($request->user()),
            $request->page(),
            $request->perPage(),
            AssetLocationResource::class,
        );
    }
}
