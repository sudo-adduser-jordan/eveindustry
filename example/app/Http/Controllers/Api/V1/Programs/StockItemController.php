<?php

namespace App\Http\Controllers\Api\V1\Programs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Programs\StoreStockItemRequest;
use App\Http\Requests\Api\V1\Programs\UpdateStockItemRequest;
use App\Http\Resources\V1\Programs\StockItemResource;
use App\Http\Responses\ApiResponse;
use App\Models\IndustryStockItem;
use App\Services\IndustryStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Industry stock manager items. Only for users with the Industry Stock
 * Manager enabled (403 otherwise); you only ever see and change your own items.
 *
 * The item is looked up here rather than by route model binding so that the
 * `stock-manager` middleware answers 403 before an unknown id can answer 404.
 */
class StockItemController extends Controller
{
    public function __construct(private IndustryStockService $stock) {}

    /**
     * Track a type, or change the wanted quantity if you already track it.
     *
     * The item name is taken from the type. Returns 201 with the item.
     *
     * @response 201 array{data: StockItemResource, meta: object}
     */
    public function store(StoreStockItemRequest $request): JsonResponse
    {
        $item = $this->stock->saveItem(
            $request->user()->id,
            (int) $request->validated('type_id'),
            (int) $request->validated('desired_quantity'),
        );

        return ApiResponse::success(new StockItemResource($item), status: 201);
    }

    /**
     * Change the wanted quantity of a stock item.
     *
     * 404 for an unknown item or one that is not yours.
     *
     * @response array{data: StockItemResource, meta: object}
     */
    public function update(UpdateStockItemRequest $request, int $item): JsonResponse
    {
        $stockItem = IndustryStockItem::findOrFail($item);
        Gate::authorize('update', $stockItem);

        $this->stock->updateItemQuantity($stockItem, (int) $request->validated('desired_quantity'));

        return ApiResponse::success(new StockItemResource($stockItem));
    }

    /**
     * Stop tracking a stock item.
     *
     * 404 for an unknown item or one that is not yours.
     */
    public function destroy(int $item): Response
    {
        $stockItem = IndustryStockItem::findOrFail($item);
        Gate::authorize('delete', $stockItem);

        $this->stock->deleteItem($stockItem);

        return response()->noContent();
    }
}
