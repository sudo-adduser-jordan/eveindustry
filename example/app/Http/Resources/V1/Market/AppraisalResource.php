<?php

namespace App\Http\Resources\V1\Market;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A contract appraisal from ContractAppraisalService::appraise().
 */
class AppraisalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var list<AppraisalItemResource> */
            'items' => AppraisalItemResource::collection($this->resource['items'])->resolve($request),
            /** Sum of line totals (ISK). */
            'total_value' => (float) $this->resource['total_value'],
            /** @var list<string> Input lines that match no type name. */
            'unmatched' => array_values(array_map('strval', $this->resource['unmatched'])),
        ];
    }
}
