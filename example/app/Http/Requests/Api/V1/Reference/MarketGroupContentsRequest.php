<?php

namespace App\Http\Requests\Api\V1\Reference;

use App\Http\Requests\Api\V1\ApiRequest;

class MarketGroupContentsRequest extends ApiRequest
{
    use FiltersMetaGroups;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->metaGroupRules();
    }
}
