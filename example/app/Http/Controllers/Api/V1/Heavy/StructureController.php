<?php

namespace App\Http\Controllers\Api\V1\Heavy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Heavy\StructureIndexRequest;
use App\Http\Resources\V1\Heavy\StructureResource;
use App\Http\Responses\ApiResponse;
use App\Models\SdeSolarSystem;
use App\Services\Heavy\StructureListService;
use Illuminate\Http\JsonResponse;

class StructureController extends Controller
{
    /**
     * Your structures.
     *
     * The user's own station and structure configs for the calculators,
     * defaults first then by name, optionally for one activity.
     *
     * @response array{data: list<StructureResource>, meta: array{pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function index(StructureIndexRequest $request, StructureListService $structures): JsonResponse
    {
        $filters = $request->filled('activity_type') ? ['activity_type' => $request->validated('activity_type')] : [];

        $configs = $structures->forUser($request->user(), $filters);
        $names = SdeSolarSystem::whereIn('solar_system_id', $configs->pluck('solar_system_id')->filter()->unique()->all())
            ->pluck('solar_system_name', 'solar_system_id');
        foreach ($configs as $config) {
            $config->setAttribute('solar_system_name', $names[$config->solar_system_id] ?? null);
        }

        return ApiResponse::paginatedArray(
            $configs->all(),
            $request->page(),
            $request->perPage(),
            StructureResource::class,
        );
    }
}
