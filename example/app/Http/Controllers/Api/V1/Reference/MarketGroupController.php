<?php

namespace App\Http\Controllers\Api\V1\Reference;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reference\MarketGroupContentsRequest;
use App\Http\Resources\V1\Reference\MarketGroupContentsResource;
use App\Http\Resources\V1\Reference\MarketGroupResource;
use App\Http\Resources\V1\Reference\MarketGroupTreeResource;
use App\Http\Responses\ApiResponse;
use App\Services\Reference\MarketGroupService;
use Illuminate\Http\JsonResponse;

class MarketGroupController extends Controller
{
    /**
     * Root market groups.
     *
     * Top-level market groups by name. A small fixed SDE list, so not paginated.
     *
     * @response array{data: list<MarketGroupResource>, meta: object}
     */
    public function index(MarketGroupService $marketGroups): JsonResponse
    {
        return ApiResponse::success(MarketGroupResource::collection($marketGroups->rootGroups()));
    }

    /**
     * Market group tree.
     *
     * Every market group that leads to a Tech I manufacturable item, nested,
     * without items.
     *
     * @response array{data: list<MarketGroupTreeResource>, meta: object}
     */
    public function tree(MarketGroupService $marketGroups): JsonResponse
    {
        return ApiResponse::success(MarketGroupTreeResource::collection($marketGroups->tree()));
    }

    /**
     * Market group contents.
     *
     * The group, its breadcrumbs, child groups and the published items listed
     * in it. `exclude_meta_groups[]` leaves out meta groups.
     *
     * @response array{data: MarketGroupContentsResource, meta: object}
     */
    public function show(int $marketGroupId, MarketGroupContentsRequest $request, MarketGroupService $marketGroups): JsonResponse
    {
        $contents = $marketGroups->contents($marketGroupId, $request->excludeMetaGroups());
        abort_if($contents === null, 404, 'Market group not found.');

        return ApiResponse::success(new MarketGroupContentsResource($contents));
    }
}
