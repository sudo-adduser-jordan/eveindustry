<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Market\AppraisalRequest;
use App\Http\Resources\V1\Market\AppraisalResource;
use App\Http\Responses\ApiResponse;
use App\Services\ContractAppraisalService;
use App\Services\MarketFreshnessService;
use Illuminate\Http\JsonResponse;

class AppraisalController extends Controller
{
    public function __construct(
        private ContractAppraisalService $appraisals,
        private MarketFreshnessService $freshness,
    ) {}

    /**
     * Appraise a list of items from public contracts.
     *
     * One item name per line. Each type is priced from item exchange
     * contracts that contain only that type, across all regions. Nothing is
     * stored. `meta.freshness` describes the public contract snapshot (last
     * hourly import), not regional orders.
     *
     * @response array{data: AppraisalResource, meta: array{freshness: array{prices_as_of: string|null, costs_as_of: null, source: string, stale: bool}}}
     */
    public function store(AppraisalRequest $request): JsonResponse
    {
        $result = $this->appraisals->appraise($request->itemsText(), $request->contractFilter(), $request->priceMethod());

        return ApiResponse::success(new AppraisalResource($result), [
            'freshness' => $this->freshness->contractsFreshnessMeta(),
        ]);
    }
}
