<?php

namespace App\Policies;

use App\Models\MoonScanResult;
use App\Models\User;
use App\Services\Planning\MoonOccupationService;
use Illuminate\Auth\Access\Response;

/**
 * Moon scan results are shared by corporation: a user may change a moon only
 * when their acting character's corporation owns the scan system.
 *
 * Mirrors the old route: no corporation is a 422, and a moon of another
 * corporation looks like a missing one (404).
 */
class MoonScanResultPolicy
{
    public function __construct(private MoonOccupationService $moons) {}

    public function update(User $user, MoonScanResult $moon): Response
    {
        $corporationId = $this->moons->corporationIdFor($user);

        if (! $corporationId) {
            return Response::denyWithStatus(422, 'No corporation found.');
        }

        $moon->loadMissing('system');

        return (int) $moon->system?->corporation_id === (int) $corporationId
            ? Response::allow()
            : Response::denyAsNotFound('Moon not found.');
    }
}
