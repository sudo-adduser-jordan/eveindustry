<?php

namespace App\Http\Controllers\Api\V1\Reference;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reference\SearchRequest;
use App\Http\Resources\V1\Reference\InventionChainResource;
use App\Http\Resources\V1\Reference\TypeSummaryResource;
use App\Http\Responses\ApiResponse;
use App\Services\Reference\InventionItemService;
use Illuminate\Http\JsonResponse;

class InventionItemController extends Controller
{
    public const SEARCH_LIMIT = 20;

    /**
     * Search inventable T2 items.
     *
     * Published Tech II items with an invention chain whose name contains
     * `q`, by name, at most 20 (not paginated).
     *
     * @response array{data: list<TypeSummaryResource>, meta: array{limit: int}}
     */
    public function index(SearchRequest $request, InventionItemService $items): JsonResponse
    {
        return ApiResponse::success(
            TypeSummaryResource::collection($items->search($request->term())),
            ['limit' => self::SEARCH_LIMIT],
        );
    }

    /**
     * Invention chain for a T2 item.
     *
     * 404 when the item is not a Tech II item that can be invented.
     *
     * @response array{data: InventionChainResource, meta: object}
     */
    public function show(int $typeId, InventionItemService $items): JsonResponse
    {
        $chain = $items->chain($typeId);
        abort_if($chain === null, 404, 'No invention chain for this item.');

        return ApiResponse::success(new InventionChainResource($chain));
    }
}
