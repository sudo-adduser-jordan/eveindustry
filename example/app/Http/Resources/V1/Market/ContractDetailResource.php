<?php

namespace App\Http\Resources\V1\Market;

use App\Models\PublicContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A public contract with its item lines and the courier amounts.
 *
 * @mixin PublicContract
 */
class ContractDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'contract_id' => (int) $this->contract_id,
            /** `item_exchange`, `auction` or `courier`. */
            'type' => (string) $this->type,
            /** @var string|null */
            'title' => $this->title !== null && $this->title !== '' ? (string) $this->title : null,
            'region_id' => (int) $this->region_id,
            'issuer_id' => (int) $this->issuer_id,
            /** ISK. */
            'price' => (float) $this->price,
            /** m3. */
            'volume' => (float) $this->volume,
            /** @var string|null ISO 8601. */
            'date_issued' => $this->date_issued?->toIso8601String(),
            /** @var string|null ISO 8601. */
            'date_expired' => $this->date_expired?->toIso8601String(),
            /** Number of item lines on the contract. */
            'items_count' => count($this->items),
            /** @var float|null ISK; null when the contract has none. */
            'buyout' => $this->buyout > 0 ? (float) $this->buyout : null,
            /** @var float|null ISK (courier). */
            'reward' => $this->reward > 0 ? (float) $this->reward : null,
            /** @var float|null ISK (courier). */
            'collateral' => $this->collateral > 0 ? (float) $this->collateral : null,
            /** @var list<ContractItemResource> */
            'items' => ContractItemResource::collection($this->items)->resolve($request),
        ];
    }
}
