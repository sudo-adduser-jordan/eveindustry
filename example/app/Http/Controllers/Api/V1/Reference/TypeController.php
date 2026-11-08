<?php

namespace App\Http\Controllers\Api\V1\Reference;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reference\TypeSearchRequest;
use App\Http\Resources\V1\Reference\MarketTypeResource;
use App\Http\Resources\V1\Reference\TypeResource;
use App\Http\Responses\ApiResponse;
use App\Services\Reference\MarketGroupService;
use App\Services\Reference\TypeLookupService;
use Illuminate\Http\JsonResponse;

class TypeController extends Controller
{
    /**
     * Search market types.
     *
     * Published types that have a market group and whose name contains `q`,
     * by name, at most 50 (not paginated). `exclude_meta_groups[]` leaves out
     * meta groups (1 Tech I, 2 Tech II, 4 Faction, ...).
     *
     * @response array{data: list<MarketTypeResource>, meta: array{limit: int}}
     */
    public function index(TypeSearchRequest $request, MarketGroupService $marketGroups): JsonResponse
    {
        return ApiResponse::success(
            MarketTypeResource::collection($marketGroups->searchTypes($request->term(), $request->excludeMetaGroups())),
            ['limit' => MarketGroupService::SEARCH_LIMIT],
        );
    }

    /**
     * Type details.
     *
     * One inventory type from the SDE, or from ESI when the SDE has no row.
     *
     * @response array{data: TypeResource, meta: object}
     */
    public function show(int $typeId, TypeLookupService $types): JsonResponse
    {
        $type = $types->find($typeId);
        abort_if($type === null, 404, 'Type not found.');

        return ApiResponse::success(new TypeResource(['type_id' => $typeId] + $type));
    }
}
