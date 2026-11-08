<?php

namespace App\Http\Resources\V1\Market;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One item line on a public contract.
 */
class ContractItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type_id' => (int) $this->type_id,
            /** The SDE name, or "Unknown Type #id". */
            'type_name' => (string) $this->type_name,
            'quantity' => (int) $this->quantity,
            /** False when the contract asks for this item instead of offering it. */
            'is_included' => (bool) $this->is_included,
            'is_blueprint_copy' => (bool) $this->is_blueprint_copy,
            /** @var int|null */
            'material_efficiency' => $this->material_efficiency,
            /** @var int|null */
            'time_efficiency' => $this->time_efficiency,
            /** @var int|null */
            'runs' => $this->runs,
        ];
    }
}
