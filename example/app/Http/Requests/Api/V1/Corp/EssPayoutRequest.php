<?php

namespace App\Http\Requests\Api\V1\Corp;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Record a payout a member saw on a tracked system.
 */
class EssPayoutRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** When it paid out (UTC). Defaults to now. */
            'occurred_at' => 'nullable|date',
            'amount_isk' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
