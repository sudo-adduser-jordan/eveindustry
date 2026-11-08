<?php

namespace App\Services;

use App\Models\BlueprintActivity;
use App\Models\EveType;

class ReactionFormulaResearchService
{
    /** EVE SDE group IDs for reaction formula categories */
    public const GROUP_COMPOSITE_REACTIONS = 1888;

    public const GROUP_BIOCHEMICAL_REACTIONS = 1889;

    public const GROUP_HYBRID_REACTIONS = 4097;

    public const CATEGORIES = [
        self::GROUP_COMPOSITE_REACTIONS => 'Composite',
        self::GROUP_BIOCHEMICAL_REACTIONS => 'Biochemical',
        self::GROUP_HYBRID_REACTIONS => 'Hybrid',
    ];

    /**
     * ESI activity_id for reaction jobs (used in the industry/jobs endpoint).
     * Distinct from BlueprintActivity::ACTIVITY_REACTION (11), which is the SDE value.
     */
    private const ESI_ACTIVITY_REACTIONS = 9;

    public function __construct(private EsiService $esiService) {}

    /**
     * Get all reaction formulas with ownership info.
     *
     * @return array{formulas: \Illuminate\Support\Collection, categories: array, hasBlueprintData: bool, charactersWithoutScope: array}
     */
    public function getReactionFormulaData(
        \App\Models\User $user,
        ?string $search = null,
        ?int $groupId = null,
        string $sortBy = 'name',
        string $sortDir = 'asc',
    ): array {
        $hasBlueprintData = $this->userHasBlueprintScope($user);
        $ownedFormulas = $hasBlueprintData ? $this->getOwnedFormulas($user) : [];
        $charactersWithoutScope = $this->getCharactersWithoutScope($user);

        $query = EveType::query()
            ->join('blueprint_activities', 'eve_types.type_id', '=', 'blueprint_activities.blueprint_type_id')
            ->leftJoin('inv_groups', 'eve_types.group_id', '=', 'inv_groups.group_id')
            ->where('blueprint_activities.activity_id', BlueprintActivity::ACTIVITY_REACTION)
            ->where('eve_types.published', true)
            ->select([
                'eve_types.type_id',
                'eve_types.name',
                'eve_types.group_id',
                'inv_groups.name as group_name',
            ]);

        if ($search) {
            $query->where('eve_types.name', 'like', "%{$search}%");
        }

        if ($groupId) {
            $query->where('eve_types.group_id', $groupId);
        }

        $sortColumn = match ($sortBy) {
            'owned' => 'eve_types.name',
            'category' => 'inv_groups.name',
            default => 'eve_types.name',
        };
        $query->orderBy($sortColumn, $sortDir === 'desc' ? 'desc' : 'asc');

        $formulas = $query->get();

        if ($sortBy === 'owned') {
            $formulas = $formulas->sortBy(function ($formula) use ($ownedFormulas) {
                return isset($ownedFormulas[$formula->type_id]) ? 0 : 1;
            }, SORT_REGULAR, $sortDir === 'desc')->values();
        }

        $formulas->transform(function ($formula) use ($ownedFormulas) {
            $owned = $ownedFormulas[$formula->type_id] ?? null;

            $formula->owned = $owned !== null;
            $formula->quantity = $owned['quantity'] ?? 0;
            $formula->owner_names = $owned['owner_names'] ?? [];
            $formula->category = self::CATEGORIES[$formula->group_id] ?? 'Other';

            return $formula;
        });

        return [
            'formulas' => $formulas,
            'categories' => self::CATEGORIES,
            'hasBlueprintData' => $hasBlueprintData,
            'charactersWithoutScope' => $charactersWithoutScope,
        ];
    }

    /**
     * Check if any of the user's characters have the blueprint scope.
     */
    public function userHasBlueprintScope(\App\Models\User $user): bool
    {
        foreach ($user->characters as $character) {
            if ($character->hasScope('esi-characters.read_blueprints.v1')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all reaction formulas owned by the user across all characters, keyed by type_id.
     * Sums up quantities across characters and tracks which characters own each formula.
     * Also counts formulas that are currently locked in active or ready industry jobs.
     */
    public function getOwnedFormulas(\App\Models\User $user): array
    {
        // Build a set of all reaction formula type IDs for fast lookup
        $reactionFormulaTypeIds = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_REACTION)
            ->pluck('blueprint_type_id')
            ->flip()
            ->all();

        $owned = [];

        foreach ($user->characters as $character) {
            // --- Blueprints sitting in inventory ---
            if ($character->is_esi_active !== false && $character->hasScope('esi-characters.read_blueprints.v1')) {
                $blueprints = $this->esiService->getCharacterBlueprints($character);

                foreach ($blueprints as $bp) {
                    $typeId = $bp['type_id'];

                    // Only include reaction formulas
                    if (! isset($reactionFormulaTypeIds[$typeId])) {
                        continue;
                    }

                    // quantity > 0 for stacked originals; -1 means it's a BPC (unlikely for formulas)
                    $quantity = max(0, (int) ($bp['quantity'] ?? 1));

                    if (! isset($owned[$typeId])) {
                        $owned[$typeId] = [
                            'quantity' => $quantity,
                            'owner_names' => [$character->name],
                        ];
                    } else {
                        $owned[$typeId]['quantity'] += $quantity;

                        if (! in_array($character->name, $owned[$typeId]['owner_names'])) {
                            $owned[$typeId]['owner_names'][] = $character->name;
                        }
                    }
                }
            }

            // --- Formulas currently locked in active or ready industry jobs ---
            if ($character->hasScope('esi-industry.read_character_jobs.v1')) {
                $jobs = $this->esiService->getCharacterIndustryJobs($character) ?? [];

                foreach ($jobs as $job) {
                    // Only active/ready reaction jobs (formula is locked, not in blueprint list)
                    if (! in_array($job['status'] ?? '', ['active', 'ready'])) {
                        continue;
                    }

                    if (($job['activity_id'] ?? 0) !== self::ESI_ACTIVITY_REACTIONS) {
                        continue;
                    }

                    $typeId = $job['blueprint_type_id'] ?? 0;
                    if (! $typeId || ! isset($reactionFormulaTypeIds[$typeId])) {
                        continue;
                    }

                    if (! isset($owned[$typeId])) {
                        $owned[$typeId] = [
                            'quantity' => 1,
                            'owner_names' => [$character->name],
                        ];
                    } else {
                        $owned[$typeId]['quantity'] += 1;

                        if (! in_array($character->name, $owned[$typeId]['owner_names'])) {
                            $owned[$typeId]['owner_names'][] = $character->name;
                        }
                    }
                }
            }
        }

        return $owned;
    }

    /**
     * Get characters that do not have the blueprint read scope.
     *
     * @return array<array{character_id: int, name: string}>
     */
    public function getCharactersWithoutScope(\App\Models\User $user): array
    {
        $missing = [];

        foreach ($user->characters as $character) {
            if (! $character->hasScope('esi-characters.read_blueprints.v1')) {
                $missing[] = [
                    'character_id' => $character->character_id,
                    'name' => $character->name,
                ];
            }
        }

        return $missing;
    }
}
