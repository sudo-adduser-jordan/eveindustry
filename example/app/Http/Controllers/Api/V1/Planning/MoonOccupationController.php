<?php

namespace App\Http\Controllers\Api\V1\Planning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Planning\MoonOccupationRequest;
use App\Http\Resources\V1\Planning\MoonOccupationResource;
use App\Http\Responses\ApiResponse;
use App\Models\MoonScanResult;
use App\Services\Planning\MoonOccupationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class MoonOccupationController extends Controller
{
    public function __construct(private MoonOccupationService $moons) {}

    /**
     * Set who occupies a scanned moon.
     *
     * Moons are shared by corporation: the user's main character's corporation
     * (any character if there is no main) must own the scan system. Without a
     * corporation the answer is 422; a moon of another corporation is 404.
     * With `occupied` false the occupier is cleared.
     *
     * @response array{data: MoonOccupationResource, meta: object}
     */
    public function update(MoonOccupationRequest $request, int $moonId): JsonResponse
    {
        $moon = MoonScanResult::findOrFail($moonId);

        Gate::authorize('update', $moon);

        return ApiResponse::success(MoonOccupationResource::make($this->moons->update($moon, $request->validated())));
    }
}
