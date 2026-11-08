<?php

namespace App\Policies;

use App\Models\User;
use App\Services\AssetService;
use App\Services\CorporateDonationService;
use App\Services\CorporationProjectsService;
use App\Services\EssTrackerService;
use Illuminate\Auth\Access\Response;

/**
 * Who may read their corporation's data on /api/v1. Each rule is the check
 * the matching service already relies on; the gates are registered in
 * CorpServiceProvider and attached to routes with the `can:` middleware, so a
 * failed check is a 403 with code "forbidden".
 */
class CorporationPolicy
{
    public const VIEW_ASSETS = 'corporation.view-assets';

    public const VIEW_DONATIONS = 'corporation.view-donations';

    public const VIEW_ESS = 'corporation.view-ess';

    public const VIEW_PROJECTS = 'corporation.view-projects';

    public const VIEW_STRUCTURE_TIMERS = 'corporation.view-structure-timers';

    public function __construct(
        private AssetService $assets,
        private CorporateDonationService $donations,
        private CorporationProjectsService $projects,
        private EssTrackerService $ess,
    ) {}

    /**
     * The main character is in a corporation, is a Director/CEO and has the
     * esi-assets.read_corporation_assets.v1 scope. A denial says which check
     * failed, so the page can tell the user what to fix.
     */
    public function viewAssets(User $user): Response
    {
        $denial = $this->assets->corporationAssetsDenial($this->assets->mainCharacterFor($user->id));

        return $denial === null ? Response::allow() : Response::deny($denial);
    }

    /**
     * Donation tracking has been enabled for the corporation of one of the
     * user's characters.
     */
    public function viewDonations(User $user): Response
    {
        return $this->donations->getConfigForUser($user->id) !== null
            ? Response::allow()
            : Response::deny('Donation tracking is not enabled for your corporation.');
    }

    /**
     * The user's main (or, without a main, any) character is in a corporation.
     */
    public function viewEss(User $user): Response
    {
        // Same falsy check as the old route: a 0 corporation_id means none.
        return $this->ess->corporationIdForUser($user->id)
            ? Response::allow()
            : Response::deny('No corporation associated with your account.');
    }

    /**
     * Same rule as the ESS tracker: the user's main (or any) character is in
     * a corporation, whose structure timers they then read and edit.
     */
    public function viewStructureTimers(User $user): Response
    {
        return $this->viewEss($user);
    }

    /**
     * The main character is in a corporation and has the
     * esi-corporations.read_projects.v1 scope.
     */
    public function viewProjects(User $user): Response
    {
        return $this->projects->getUserProjectsCharacters($user->id)->isNotEmpty()
            ? Response::allow()
            : Response::deny('Your main character is not in a corporation or lacks the esi-corporations.read_projects.v1 scope. Re-authenticate to grant it.');
    }
}
