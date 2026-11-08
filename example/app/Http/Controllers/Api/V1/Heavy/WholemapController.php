<?php

namespace App\Http\Controllers\Api\V1\Heavy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Heavy\ListRequest;
use App\Http\Requests\Api\V1\Heavy\LocationRequest;
use App\Http\Requests\Api\V1\Heavy\WholemapRequest;
use App\Http\Resources\V1\Heavy\JumpLogResource;
use App\Http\Resources\V1\Heavy\LocationResource;
use App\Http\Resources\V1\Heavy\WholemapMapResource;
use App\Http\Responses\ApiResponse;
use App\Models\WormholeConnection;
use App\Services\Heavy\CurrentLocationService;
use App\Services\Heavy\HeavyServiceException;
use App\Services\Heavy\WholemapMapService;
use Illuminate\Http\JsonResponse;

class WholemapController extends Controller
{
    /**
     * Wormhole map.
     *
     * The user's signatures, wormhole connections and system notes from the
     * last 24 hours (mapped data expires after a day). `system_id` narrows
     * the signatures and notes to one system; connections are always the
     * whole chain.
     *
     * @response array{data: WholemapMapResource}
     */
    public function map(WholemapRequest $request, WholemapMapService $map): JsonResponse
    {
        $systemId = $request->validated('system_id');

        return ApiResponse::success(new WholemapMapResource(
            $map->forUser((int) $request->user()->id, $systemId === null ? null : (int) $systemId)
        ));
    }

    /**
     * Connection jump logs.
     *
     * Recorded jumps through one of the user's wormhole connections, newest
     * first. Someone else's connection is 403.
     *
     * @response array{data: list<JumpLogResource>, meta: array{pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function jumpLogs(ListRequest $request, WormholeConnection $connection): JsonResponse
    {
        return ApiResponse::paginated(
            $connection->jumpLogs()->orderByDesc('jumped_at')->paginate($request->perPage(), ['*'], 'page', $request->page()),
            JumpLogResource::class,
        );
    }

    /**
     * Current location.
     *
     * Asks ESI where the character is right now (never cached) and stores
     * the system name on the character. Defaults to the main character.
     * 422 when the character is not the user's, there is no main character,
     * or the location scope was not granted; 502 when ESI cannot answer.
     *
     * @response array{data: LocationResource, meta: array<string, mixed>}
     */
    public function location(LocationRequest $request, CurrentLocationService $location): JsonResponse
    {
        try {
            $current = $location->current($request->user(), $request->validated('character_id'));
        } catch (HeavyServiceException $e) {
            abort($e->status, $e->getMessage());
        }

        return ApiResponse::success(new LocationResource($current));
    }
}
