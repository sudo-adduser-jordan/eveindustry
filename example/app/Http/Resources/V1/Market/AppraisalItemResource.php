<?php

namespace App\Http\Resources\V1\Market;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One appraised line: a type, how many were listed, and its contract price.
 */
class AppraisalItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'type_id' => (int) $row['type_id'],
            'name' => (string) $row['name'],
            'quantity' => (int) $row['quantity'],
            /** Per-unit price from single-type item exchange contracts (ISK); 0 when none were found. */
            'unit_price' => (float) $row['unit_price'],
            'line_total' => (float) $row['line_total'],
            'contracts_found' => (int) $row['contracts_found'],
        ];
    }
}
