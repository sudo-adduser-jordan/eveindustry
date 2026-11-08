<?php

namespace App\Http\Controllers\Api\V1\Reference;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Reference\ProductCategoryResource;
use App\Http\Responses\ApiResponse;
use App\Services\ManufacturingBrowserService;
use Illuminate\Http\JsonResponse;

class ManufacturingCategoryController extends Controller
{
    /**
     * Manufacturing product categories.
     *
     * Item categories that contain Tech I manufacturable products (invented
     * blueprints excluded), by name, with product counts. A small fixed SDE
     * list, so not paginated.
     *
     * @response array{data: list<ProductCategoryResource>, meta: object}
     */
    public function index(ManufacturingBrowserService $browser): JsonResponse
    {
        return ApiResponse::success(ProductCategoryResource::collection($browser->getAvailableProductCategories()));
    }
}
