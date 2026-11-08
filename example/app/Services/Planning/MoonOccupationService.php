<?php

namespace App\Services\Planning;

use App\Models\Character;
use App\Models\MoonScanResult;
use App\Models\User;

/**
 * Occupation status of corporation moon scan results. Shared by the legacy
 * moon-scan route and /api/v1.
 */
class MoonOccupationService
{
    /**
     * The corporation the user acts for: their main character's, falling back
     * to any character when no main is set.
     */
    public function corporationIdFor(User $user): ?int
    {
        $character = Character::where('user_id', $user->id)->where('is_main', true)->first()
            ?? Character::where('user_id', $user->id)->first();

        return $character?->corporation_id;
    }

    /**
     * The moon, only if its scan system belongs to the corporation.
     */
    public function findForCorporation(int $corporationId, int $moonId): ?MoonScanResult
    {
        return MoonScanResult::whereHas('system', function ($q) use ($corporationId) {
            $q->where('corporation_id', $corporationId);
        })->find($moonId);
    }

    /**
     * Marking a moon unoccupied clears the occupier, whatever was sent.
     *
     * @param  array<string, mixed>  $data  validated: occupied, corporation_id?, corporation_name?
     */
    public function update(MoonScanResult $moon, array $data): MoonScanResult
    {
        $occupied = (bool) $data['occupied'];

        $moon->update([
            'occupied' => $occupied,
            'occupying_corporation_id' => $occupied ? ($data['corporation_id'] ?? null) : null,
            'occupying_corporation_name' => $occupied ? ($data['corporation_name'] ?? null) : null,
        ]);

        return $moon;
    }
}
