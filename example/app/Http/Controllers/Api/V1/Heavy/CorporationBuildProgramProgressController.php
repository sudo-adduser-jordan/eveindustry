<?php

namespace App\Http\Controllers\Api\V1\Heavy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Heavy\CorporateProgressRequest;
use App\Http\Requests\Api\V1\Heavy\ListRequest;
use App\Http\Resources\V1\Heavy\AssetLocationResource;
use App\Http\Resources\V1\Heavy\BuildProgramProgressResource;
use App\Http\Responses\ApiResponse;
use App\Models\CorporationBuildProgram;
use App\Services\Heavy\CorporateBuildProgramProgressService;
use App\Services\Heavy\HeavyServiceException;
use Illuminate\Http\JsonResponse;

class CorporationBuildProgramProgressController extends Controller
{
    public function __construct(private CorporateBuildProgramProgressService $service) {}

    /**
     * Corporate build program progress.
     *
     * Materials are counted in the corporation assets at the input
     * locations, finished products at the output locations (all locations
     * when no filter is given). Assets are fetched with the enabling
     * director's token. A program of another corporation is 404.
     *
     * @response array{data: BuildProgramProgressResource, meta: array<string, mixed>}
     */
    public function progress(CorporateProgressRequest $request, CorporationBuildProgram $program): JsonResponse
    {
        try {
            $config = $this->service->configFor($request->user(), $program);
            $result = $this->service->progress($config, $program, $request->inputLocationIds(), $request->outputLocationIds());
        } catch (HeavyServiceException $e) {
            abort($e->status, $e->getMessage());
        }

        // The resource lists character names; here it is the director alone.
        $result['character_names'] = [$result['character_name']];

        return ApiResponse::success(new BuildProgramProgressResource($result));
    }

    /**
     * Corporate build program asset locations.
     *
     * Stations and structures that hold corporation assets, by solar system
     * then name, for the input/output location filters. Empty when the
     * director character is gone. A program of another corporation is 404.
     *
     * @response array{data: list<AssetLocationResource>, meta: array{pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function assetLocations(ListRequest $request, CorporationBuildProgram $program): JsonResponse
    {
        try {
            $config = $this->service->configFor($request->user(), $program);
        } catch (HeavyServiceException $e) {
            abort($e->status, $e->getMessage());
        }

        return ApiResponse::paginatedArray(
            $this->service->assetLocations($request->user(), $config),
            $request->page(),
            $request->perPage(),
            AssetLocationResource::class,
        );
    }
}
