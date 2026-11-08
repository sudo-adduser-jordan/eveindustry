<?php

namespace App\Http\Controllers\Api\V1\Reference;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reference\SearchRequest;
use App\Http\Resources\V1\Reference\CorporationResource;
use App\Http\Responses\ApiResponse;
use App\Services\Reference\CorporationSearchService;
use Illuminate\Http\JsonResponse;

class CorporationController extends Controller
{
    /**
     * Search corporations.
     *
     * ESI corporation search run as the user's main character (or any of
     * their characters), at most 10 results (not paginated). Empty when the
     * user has no character or ESI returns nothing.
     *
     * @response array{data: list<CorporationResource>, meta: array{limit: int}}
     */
    public function index(SearchRequest $request, CorporationSearchService $corporations): JsonResponse
    {
        return ApiResponse::success(
            CorporationResource::collection($corporations->searchForUser($request->user()?->id, $request->term())),
            ['limit' => CorporationSearchService::LIMIT],
        );
    }
}
