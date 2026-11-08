<?php

namespace App\Http\Resources\V1\Corp;

use App\Models\EssPayoutEvent;
use App\Models\EssTrackedSystem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A system on the corporation ESS tracker with its next payout and the
 * latest recorded payouts.
 *
 * @mixin EssTrackedSystem
 */
class EssSystemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EssTrackedSystem $system */
        $system = $this->resource;
        $now = CarbonImmutable::now();

        return [
            'id' => (int) $system->id,
            'solar_system_id' => (int) $system->solar_system_id,
            'solar_system_name' => (string) $system->solar_system_name,
            'cycle_minutes' => (int) $system->cycle_minutes,
            /** @var string|null ISO 8601. */
            'cycle_anchor_at' => $system->cycle_anchor_at?->toIso8601String(),
            /** ISO 8601. */
            'next_payout_at' => $system->nextPayoutAt($now)->toIso8601String(),
            'seconds_until_next_payout' => $system->secondsUntilNextPayout($now),
            /** @var string|null */
            'notes' => $system->notes,
            /** Up to five, newest first. */
            'recent_payouts' => $system->payoutEvents->map(fn (EssPayoutEvent $e) => [
                'id' => (int) $e->id,
                /** @var string|null ISO 8601. */
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                /** @var float|null ISK as recorded by a member. */
                'amount_isk' => $e->amount_isk === null ? null : (float) $e->amount_isk,
                /** @var string|null */
                'notes' => $e->notes,
            ])->values()->all(),
        ];
    }
}
