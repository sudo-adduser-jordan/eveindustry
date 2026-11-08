<?php

namespace App\Http\Controllers\Api\V1\Planning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Planning\ConnectionStoreRequest;
use App\Http\Requests\Api\V1\Planning\ConnectionUpdateRequest;
use App\Http\Requests\Api\V1\Planning\JumpLogStoreRequest;
use App\Http\Resources\V1\Planning\ConnectionResource;
use App\Http\Resources\V1\Planning\JumpLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\WholemapSignature;
use App\Models\WormholeConnection;
use App\Services\Planning\WholemapWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Wormhole connections and their jump logs. Owner only (403 `forbidden`).
 */
class WholemapConnectionController extends Controller
{
    public function __construct(private WholemapWriter $writer) {}

    /**
     * Map a connection.
     *
     * The signature it hangs off must be the user's own (403 otherwise).
     *
     * @response array{data: ConnectionResource, meta: object}
     */
    public function store(ConnectionStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        Gate::authorize('update', WholemapSignature::findOrFail($data['signature_id']));

        return ApiResponse::success(
            ConnectionResource::make($this->writer->createConnection($request->user(), $data)),
            [],
            201,
        );
    }

    /**
     * Update a connection. Partial: only the fields sent change.
     *
     * @response array{data: ConnectionResource, meta: object}
     */
    public function update(ConnectionUpdateRequest $request, WormholeConnection $connection): JsonResponse
    {
        Gate::authorize('update', $connection);

        return ApiResponse::success(
            ConnectionResource::make($this->writer->updateConnection($connection, $request->validated()))
        );
    }

    /**
     * Delete a connection and its jump logs.
     */
    public function destroy(WormholeConnection $connection): Response
    {
        Gate::authorize('delete', $connection);

        $this->writer->deleteConnection($connection);

        return response()->noContent();
    }

    /**
     * Log a jump through one of the user's connections.
     *
     * `jumped_at` is set to now.
     *
     * @response array{data: JumpLogResource, meta: object}
     */
    public function storeJumpLog(JumpLogStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        Gate::authorize('update', WormholeConnection::findOrFail($data['connection_id']));

        return ApiResponse::success(
            JumpLogResource::make($this->writer->logJump($request->user(), $data)),
            [],
            201,
        );
    }
}
