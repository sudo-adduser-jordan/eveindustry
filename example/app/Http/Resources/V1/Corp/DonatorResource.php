<?php

namespace App\Http\Resources\V1\Corp;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One donator's player donations into the corporation wallets, from
 * CorporateDonationService::getDonationsForUser().
 */
class DonatorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'donator_id' => (int) $d['donator_id'],
            'donator_name' => (string) $d['donator_name'],
            'portrait_url' => (string) ($d['portrait_url'] ?? ''),
            /** Sum of the donations in the range, in ISK (wallet amounts, not market prices). */
            'total_amount' => (float) $d['total_amount'],
            'donation_count' => (int) $d['donation_count'],
            /** @var list<array{id: int|null, date: string, amount: float, reason: string, wallet_division: int, wallet_name: string}> One row per donation. */
            'donations' => array_map(fn (array $donation) => [
                'id' => isset($donation['id']) ? (int) $donation['id'] : null,
                /** Journal date, UTC (Y-m-d H:i:s). */
                'date' => (string) $donation['date'],
                'amount' => (float) $donation['amount'],
                'reason' => (string) ($donation['reason'] ?? ''),
                'wallet_division' => (int) $donation['wallet_division'],
                'wallet_name' => (string) $donation['wallet_name'],
            ], $d['donations'] ?? []),
        ];
    }
}
