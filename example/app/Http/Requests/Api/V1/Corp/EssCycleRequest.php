<?php

namespace App\Http\Requests\Api\V1\Corp;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Change the cycle of a tracked system on the ESS tracker.
 *
 * The cycle is `cycle_hours` plus `cycle_minutes` (1 to 1440 minutes in all).
 * The next payout is given as a time from now (`next_payout_in_hours` and
 * `next_payout_in_minutes`) or as an explicit `cycle_anchor_at`; without
 * either, the current schedule stays.
 */
class EssCycleRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cycle_hours' => 'nullable|integer|min:0|max:24',
            'cycle_minutes' => 'required|integer|min:0|max:1440',
            'next_payout_in_hours' => 'nullable|integer|min:0|max:24',
            'next_payout_in_minutes' => 'nullable|integer|min:0|max:1440',
            /** ISO 8601 or any date string; used when no next payout time is given. */
            'cycle_anchor_at' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
