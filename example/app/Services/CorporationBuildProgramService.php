<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CorporationBuildProgramConfig;
use App\Models\User;
use Illuminate\Support\Collection;

class CorporationBuildProgramService
{
    /**
     * In-game role names that grant management access to corporate build programs.
     */
    public const MANAGER_ROLES = ['Director', 'Factory_Manager'];

    /**
     * ESI scope required on the director character for corp asset lookups.
     */
    public const REQUIRED_SCOPE = 'esi-assets.read_corporation_assets.v1';

    /**
     * Find the active corporate build-program config for any corporation the user belongs to.
     */
    public function getConfigForUser(int $userId): ?CorporationBuildProgramConfig
    {
        $user = User::find($userId);
        if (! $user) {
            return null;
        }

        foreach ($user->characters as $character) {
            $config = CorporationBuildProgramConfig::findActiveForCorporation($character->corporation_id);
            if ($config) {
                return $config;
            }
        }

        return null;
    }

    /**
     * Return all characters belonging to this user that have Director or CEO role
     * and the required ESI scope for corporation asset lookups.
     */
    public function getEligibleCharactersForUser(int $userId): Collection
    {
        $user = User::find($userId);
        if (! $user) {
            return collect();
        }

        return $user->characters->filter(function (Character $character) {
            $hasRole = $character->is_director_ceo
                || $character->hasRole('Director');

            return $hasRole && $character->hasScope(self::REQUIRED_SCOPE);
        })->values();
    }

    /**
     * Check whether the given user can VIEW corporate build programs for the given corporation.
     * Any user with a character in the corporation may view.
     */
    public function canUserView(User $user, int $corporationId): bool
    {
        return $user->characters()
            ->where('corporation_id', $corporationId)
            ->exists();
    }

    /**
     * Check whether the given user can MANAGE (add/remove) corporate build programs.
     * Requires Director, CEO (is_director_ceo), or Factory_Manager role.
     */
    public function canUserManage(User $user, int $corporationId): bool
    {
        return $user->characters()
            ->where('corporation_id', $corporationId)
            ->get()
            ->some(function (Character $character) {
                return $character->is_director_ceo
                    || $character->hasRole('Factory_Manager');
            });
    }

    /**
     * Enable corporate build programs using the given director/CEO character.
     */
    public function enable(Character $character, int $userId): CorporationBuildProgramConfig
    {
        return CorporationBuildProgramConfig::updateOrCreate(
            ['corporation_id' => $character->corporation_id],
            [
                'corporation_name' => $character->corporation_name,
                'character_id' => $character->character_id,
                'enabled_by_user_id' => $userId,
                'is_active' => true,
            ]
        );
    }

    /**
     * Disable corporate build programs for the given corporation.
     */
    public function disable(int $corporationId): void
    {
        CorporationBuildProgramConfig::where('corporation_id', $corporationId)
            ->update(['is_active' => false]);
    }

    /**
     * Check whether the user can change or delete this particular program:
     * their corporation has the feature on, it is the program's corporation
     * and they can manage (see canUserManage).
     */
    public function canUserManageProgram(User $user, \App\Models\CorporationBuildProgram $program): bool
    {
        $config = $this->getConfigForUser($user->id);
        if (! $config || $config->corporation_id !== $program->corporation_id) {
            return false;
        }

        return $this->canUserManage($user, $program->corporation_id);
    }
}
