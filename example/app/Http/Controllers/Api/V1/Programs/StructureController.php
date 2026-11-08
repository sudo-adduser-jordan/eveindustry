<?php

namespace App\Http\Controllers\Api\V1\Programs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Programs\StoreStructureRequest;
use App\Http\Requests\Api\V1\Programs\UpdateStructureRequest;
use App\Http\Resources\V1\Programs\StructureResource;
use App\Http\Responses\ApiResponse;
use App\Models\StationConfig;
use App\Services\Programs\StructureConfigWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Your structures (station configs). Other users' structures are 404.
 */
class StructureController extends Controller
{
    public function __construct(private StructureConfigWriter $structures) {}

    /**
     * Create a structure.
     *
     * With `is_default` it becomes the default for its activity type and your
     * other structures of that type lose the flag. Returns 201.
     *
     * @response 201 array{data: StructureResource, meta: object}
     */
    public function store(StoreStructureRequest $request): JsonResponse
    {
        $structure = $this->structures->create($request->user(), $request->validated());

        return ApiResponse::success(new StructureResource($structure), status: 201);
    }

    /**
     * Change a structure; only the fields sent are changed.
     *
     * @response array{data: StructureResource, meta: object}
     */
    public function update(UpdateStructureRequest $request, StationConfig $structure): JsonResponse
    {
        $structure = $this->structures->update($request->user(), $structure, $request->validated());

        return ApiResponse::success(new StructureResource($structure));
    }

    /**
     * Delete a structure.
     */
    public function destroy(StationConfig $structure): Response
    {
        $this->structures->delete($structure);

        return response()->noContent();
    }

    /**
     * Make a structure the default for its activity type.
     *
     * Your other structures of that activity type lose the flag.
     *
     * @response array{data: StructureResource, meta: object}
     */
    public function setDefault(\Illuminate\Http\Request $request, StationConfig $structure): JsonResponse
    {
        $structure = $this->structures->setDefault($request->user(), $structure);

        return ApiResponse::success(new StructureResource($structure));
    }
}
