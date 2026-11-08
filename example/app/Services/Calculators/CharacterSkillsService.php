<?php

namespace App\Services\Calculators;

use App\Models\Character;
use App\Services\EsiService;
use App\Services\InventionPlannerService;
use App\Services\ManufacturingCalculatorService;
use App\Services\ReactionsCalculatorService;

/**
 * The skill levels each calculator pre-fills from a character. Only a
 * user's own characters can be read (findForUser returns null otherwise).
 */
class CharacterSkillsService
{
    public function __construct(protected EsiService $esi) {}

    public function findForUser(int $userId, int $characterId): ?Character
    {
        return Character::where('character_id', $characterId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Trained level by skill id; empty when ESI returns nothing.
     *
     * @return array<int, int>
     */
    public function skillMap(Character $character): array
    {
        $skillData = $this->esi->getCharacterSkills($character);
        $skills = $skillData['skills'] ?? [];

        $skillMap = [];
        foreach ($skills as $s) {
            $skillMap[$s['skill_id']] = $s['trained_skill_level'] ?? 0;
        }

        return $skillMap;
    }

    /**
     * @return array{industry: int, advanced_industry: int}
     */
    public function manufacturing(Character $character): array
    {
        $skillMap = $this->skillMap($character);

        return [
            'industry' => $skillMap[ManufacturingCalculatorService::SKILL_INDUSTRY] ?? 0,
            'advanced_industry' => $skillMap[ManufacturingCalculatorService::SKILL_ADVANCED_INDUSTRY] ?? 0,
        ];
    }

    /**
     * @return array{reactions: int}
     */
    public function reactions(Character $character): array
    {
        $skillMap = $this->skillMap($character);

        return [
            'reactions' => $skillMap[ReactionsCalculatorService::SKILL_REACTIONS] ?? 0,
        ];
    }

    /**
     * Encryption and datacore science skills, the full skill map, and
     * Industry / Advanced Industry.
     *
     * @return array{encryption_skills: array<int, array{skill_id: int, name: string, level: int}>, science_skills: array<int, array{skill_id: int, name: string, level: int}>, skill_map: array<int, int>, industry: int, advanced_industry: int}
     */
    public function invention(Character $character): array
    {
        $skillMap = $this->skillMap($character);

        $encryptionSkills = [];
        foreach (InventionPlannerService::ENCRYPTION_SKILLS as $skillId => $skillName) {
            $encryptionSkills[] = [
                'skill_id' => $skillId,
                'name' => $skillName,
                'level' => $skillMap[$skillId] ?? 0,
            ];
        }

        $scienceSkills = [];
        foreach (InventionPlannerService::DATACORE_SKILLS as $skillId => $skillName) {
            $scienceSkills[] = [
                'skill_id' => $skillId,
                'name' => $skillName,
                'level' => $skillMap[$skillId] ?? 0,
            ];
        }

        return [
            'encryption_skills' => $encryptionSkills,
            'science_skills' => $scienceSkills,
            'skill_map' => $skillMap,
            'industry' => $skillMap[ManufacturingCalculatorService::SKILL_INDUSTRY] ?? 0,
            'advanced_industry' => $skillMap[ManufacturingCalculatorService::SKILL_ADVANCED_INDUSTRY] ?? 0,
        ];
    }
}
