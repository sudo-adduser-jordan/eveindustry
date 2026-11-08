<?php

namespace App\Http\Resources\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Top endpoints and reason categories by error count, with raw counts beside the rates.
 */
class EsiDiagnosticsEndpointsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int */
            'total_requests' => $this->resource['total_requests'],
            /** @var int */
            'total_errors' => $this->resource['total_errors'],
            /** @var list<array{operation: string, requests: int, errors: int, errors_per_1000: float|null}> */
            'operations' => $this->resource['operations'],
            /** @var list<array{operation: string, http_status: int|null, reason: string, dataset: string|null, errors: int, share_of_errors: float|null, errors_per_1000_requests: float|null}> */
            'reasons' => $this->resource['reasons'],
        ];
    }
}
