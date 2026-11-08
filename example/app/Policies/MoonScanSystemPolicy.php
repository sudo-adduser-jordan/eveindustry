<?php

namespace App\Policies;

use App\Models\MoonScanSystem;
use App\Models\User;
use App\Services\Heavy\MoonScanLookupService;
use Illuminate\Auth\Access\Response;

/**
 * Stored moon scans on /api/v1.
 *
 * Abilities: viewAny, view, update (also covers moon occupation).
 *
 * Scans belong to a corporation: the one of the user's main character (any
 * character when there is no main). Another corporation's scan is reported as
 * not found (404), like the old routes.
 */
class MoonScanSystemPolicy
{
    public function __construct(private MoonScanLookupService $scans) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MoonScanSystem $system): Response
    {
        return $this->sameCorporation($user, $system);
    }

    public function update(User $user, MoonScanSystem $system): Response
    {
        return $this->sameCorporation($user, $system);
    }

    private function sameCorporation(User $user, MoonScanSystem $system): Response
    {
        $corporationId = $this->scans->corporationIdForUser((int) $user->id);

        return $corporationId !== null && (int) $system->corporation_id === (int) $corporationId
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
