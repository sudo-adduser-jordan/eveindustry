<?php

namespace App\Http\Requests\Api\V1\Industry;

use App\Http\Requests\Api\V1\Market\MarketRequest;

class JobsOverviewRequest extends MarketRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Trade hub whose sell prices value the output. Default 10000002 (The Forge / Jita). */
            'region_id' => $this->regionRules(),
        ];
    }

    public function regionId(): int
    {
        return $this->regionInput('region_id');
    }
}
