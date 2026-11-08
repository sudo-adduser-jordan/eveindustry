<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MeResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Current user.
     *
     * The signed-in user with their linked characters (and granted ESI scopes)
     * and the `admin` / `stock_manager` feature flags.
     *
     * @response array{data: MeResource, meta: object}
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['characters' => fn ($q) => $q->orderByDesc('is_main')->orderBy('name')]);

        return ApiResponse::success(new MeResource($user));
    }
}
