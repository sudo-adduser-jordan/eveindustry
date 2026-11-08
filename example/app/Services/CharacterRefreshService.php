<?php

namespace App\Services;

use App\Models\Character;
use Illuminate\Support\Facades\Cache;

class CharacterRefreshService
{
    public function __construct(private EsiService $esiService) {}

    public function refresh(Character $character): void
    {
        $this->refreshAffiliation($character);
        // Membership changes revoke the old role evidence before other calls.
        $character->save();
        $this->refreshLocation($character);
        $this->refreshTraining($character);
        $this->refreshSkills($character);
        $this->refreshRoles($character);
        $character->save();
    }

    protected function refreshAffiliation(Character $character, $ignoreCache = false): void
    {
        $affiliation = $this->esiService->getCharacterAffiliation($character->character_id);

        if (! $affiliation) {
            return;
        }

        $corpId = $affiliation['corporation_id'] ?? $character->corporation_id;
        $allianceId = $affiliation['alliance_id'] ?? null;

        $corpInfo = $this->esiService->getCorporation($corpId, $ignoreCache);
        if ((int) $character->corporation_id !== (int) $corpId) {
            // Roles belong to the old corporation. Do not reuse them after a move.
            $character->roles = [];
            $character->is_director_ceo = false;
            Cache::forget("assets.user.{$character->user_id}");
            Cache::forget("dashboard.industry_stats.{$character->user_id}");
        }
        $character->corporation_id = $corpId;
        $character->corporation_name = $corpInfo['name'] ?? $character->corporation_name;

        $character->alliance_id = $allianceId;
        if ($allianceId) {
            $allianceInfo = $this->esiService->getAlliance($allianceId, $ignoreCache);
            $character->alliance_name = $allianceInfo['name'] ?? $character->alliance_name;
        } else {
            $character->alliance_name = null;
        }
    }

    protected function refreshLocation(Character $character, bool $ignoreCache = false): void
    {
        if (! $character->hasScope('esi-location.read_location.v1')) {
            return;
        }

        $positionData = $this->esiService->fetchPilotCurrentPosition($character, $ignoreCache);

        if (! $positionData || ! isset($positionData['solar_system_id'])) {
            return;
        }

        $systemMetadata = $this->esiService->getSystem($positionData['solar_system_id']);
        $character->cached_location = $systemMetadata['name'] ?? null;
    }

    protected function refreshTraining(Character $character, bool $ignoreCache = false): void
    {
        if (! $character->hasScope('esi-skills.read_skillqueue.v1')) {
            return;
        }

        $queueData = $this->esiService->fetchPilotTrainingQueue($character, $ignoreCache);

        if ($queueData === null || ! is_array($queueData)) {
            return;
        }

        $activeEntry = null;
        foreach ($queueData as $entry) {
            if (isset($entry['queue_position']) && $entry['queue_position'] === 0) {
                $activeEntry = $entry;
                break;
            }
        }

        if (! $activeEntry || ! isset($activeEntry['skill_id'])) {
            $character->cached_training = null;

            return;
        }

        $skillMetadata = $this->esiService->getTypeInfo($activeEntry['skill_id']);
        $skillTitle = $skillMetadata['name'] ?? "Skill {$activeEntry['skill_id']}";
        $targetLevel = $activeEntry['finished_level'] ?? '?';

        $character->cached_training = "{$skillTitle} {$targetLevel}";
    }

    protected function refreshSkills(Character $character): void
    {
        if (! $character->hasScope('esi-skills.read_skills.v1')) {
            return;
        }

        // Refresh the cached skills data so subsequent requests get up-to-date skill levels
        $this->esiService->getCharacterSkills($character, ignoreCache: true);
    }

    protected function refreshRoles(Character $character): void
    {
        if (! $character->hasScope('esi-characters.read_corporation_roles.v1')) {
            return;
        }

        $rolesData = $this->esiService->getCharacterRoles($character);
        if ($rolesData === null) {
            return;
        }

        $roles = $rolesData['roles'] ?? [];
        if ($character->roles !== $roles) {
            Cache::forget("assets.user.{$character->user_id}");
            Cache::forget("dashboard.industry_stats.{$character->user_id}");
        }
        $character->roles = $roles;
        $character->is_director_ceo = in_array('Director', $roles) || in_array('CEO', $roles);
    }
}
