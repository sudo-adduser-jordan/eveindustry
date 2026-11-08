<?php

namespace App\Http\Requests\Api\V1\Heavy;

use App\Http\Requests\Api\V1\ApiRequest;
use App\Services\MarketTradingService;
use Illuminate\Validation\Rule;

/**
 * A pasted moon scan to price, grade and store for the user's corporation.
 */
class MoonScanRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The text pasted from the in-game moon scan window (TSV). */
            'scan_data' => 'required|string|max:200000',
            /** Trade hub region to price in. Defaults to The Forge. */
            'region_id' => ['nullable', 'integer', Rule::in(array_keys(MarketTradingService::REGIONS))],
            /** How ore is valued. Defaults to raw. */
            'pricing_mode' => 'nullable|string|in:raw,compressed,refined',
        ];
    }

    public function regionId(): ?int
    {
        $region = $this->validated('region_id');

        return $region === null ? null : (int) $region;
    }
}
