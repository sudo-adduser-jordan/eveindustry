<?php

namespace App\Services;

use App\Models\BlueprintActivity;
use App\Models\EveType;

class InventionPlannerService
{
    // Base T2 BPC values from invention (before decryptor modifiers)
    public const BASE_ME = 2;

    public const BASE_TE = 4;

    public const MAX_ME = 10;

    public const MAX_TE = 20;

    public const MIN_ME = 0;

    public const MIN_TE = 0;

    // Decryptor definitions: key => [name, type_id, probability_multiplier, me_modifier, te_modifier, run_modifier]
    public const DECRYPTORS = [
        'none' => [
            'name' => 'None',
            'type_id' => null,
            'probability_multiplier' => 1.0,
            'me_modifier' => 0,
            'te_modifier' => 0,
            'run_modifier' => 0,
        ],
        'accelerant' => [
            'name' => 'Accelerant Decryptor',
            'type_id' => 34201,
            'probability_multiplier' => 1.2,
            'me_modifier' => 2,
            'te_modifier' => 10,
            'run_modifier' => 1,
        ],
        'attainment' => [
            'name' => 'Attainment Decryptor',
            'type_id' => 34202,
            'probability_multiplier' => 1.8,
            'me_modifier' => -1,
            'te_modifier' => 4,
            'run_modifier' => 4,
        ],
        'augmentation' => [
            'name' => 'Augmentation Decryptor',
            'type_id' => 34203,
            'probability_multiplier' => 0.6,
            'me_modifier' => -2,
            'te_modifier' => -2,
            'run_modifier' => 9,
        ],
        'parity' => [
            'name' => 'Parity Decryptor',
            'type_id' => 34204,
            'probability_multiplier' => 1.5,
            'me_modifier' => 1,
            'te_modifier' => -2,
            'run_modifier' => 3,
        ],
        'process' => [
            'name' => 'Process Decryptor',
            'type_id' => 34205,
            'probability_multiplier' => 1.1,
            'me_modifier' => 3,
            'te_modifier' => 6,
            'run_modifier' => 0,
        ],
        'symmetry' => [
            'name' => 'Symmetry Decryptor',
            'type_id' => 34206,
            'probability_multiplier' => 1.0,
            'me_modifier' => 1,
            'te_modifier' => 8,
            'run_modifier' => 2,
        ],
    ];

    // Encryption skill type IDs (racial variants)
    public const ENCRYPTION_SKILLS = [
        23087 => 'Amarr Encryption Methods',
        21790 => 'Caldari Encryption Methods',
        23121 => 'Gallente Encryption Methods',
        21791 => 'Minmatar Encryption Methods',
    ];

    // Datacore science skill type IDs
    public const DATACORE_SKILLS = [
        11433 => 'Electromagnetic Physics',
        11443 => 'Electronic Engineering',
        11441 => 'Graviton Physics',
        11449 => 'High Energy Physics',
        11451 => 'Hydromagnetic Physics',
        11448 => 'Laser Physics',
        11452 => 'Mechanical Engineering',
        11442 => 'Molecular Engineering',
        11445 => 'Nuclear Physics',
        11444 => 'Plasma Physics',
        11447 => 'Quantum Physics',
        11446 => 'Rocket Science',
        11455 => 'Nanite Engineering',
    ];

    // Datacore type_id => skill type_id mapping
    public const DATACORE_TO_SKILL = [
        20172 => 11433, // Datacore - Electromagnetic Physics
        20171 => 11443, // Datacore - Electronic Engineering
        20173 => 11441, // Datacore - Graviton Physics
        20174 => 11449, // Datacore - High Energy Physics
        20175 => 11451, // Datacore - Hydromagnetic Physics
        20176 => 11448, // Datacore - Laser Physics
        20424 => 11452, // Datacore - Mechanical Engineering
        20418 => 11442, // Datacore - Molecular Engineering
        20177 => 11445, // Datacore - Nuclear Physics
        20178 => 11444, // Datacore - Plasma Physics
        20179 => 11447, // Datacore - Quantum Physics
        20180 => 11446, // Datacore - Rocket Science
        25887 => 11455, // Datacore - Nanite Engineering
    ];

    public function __construct(
        protected EsiService $esi,
        protected ManufacturingCalculatorService $calculator,
        protected MarketService $marketService
    ) {}

    /**
     * Search for T2 items by name.
     * Filters to only show items that can be produced via invention.
     */
    public function searchT2Items(string $query): array
    {
        $escapedQuery = addcslashes($query, '%_');
        $types = EveType::where('name', 'like', '%'.$escapedQuery.'%')
            ->where('published', true)
            ->where('meta_group_id', EveType::META_GROUP_TECH_II)
            ->whereNotNull('market_group_id')
            ->where('name', 'not like', '%Blueprint%')
            ->where('name', 'not like', '%Reaction Formula%')
            ->orderBy('name')
            ->get();

        $results = [];
        foreach ($types as $type) {
            // Check if this item has a manufacturing blueprint
            $t2Blueprint = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
                ->whereProducesType($type->type_id)
                ->first();

            if (! $t2Blueprint) {
                continue;
            }

            // Check if the T2 BOM includes a T1 item whose blueprint has invention
            $hasTechChain = $this->hasTechChain($t2Blueprint);
            if (! $hasTechChain) {
                continue;
            }

            $results[] = [
                'type_id' => $type->type_id,
                'name' => $type->name,
                'group_id' => $type->group_id,
                'icon_url' => EsiService::getTypeImageUrl($type->type_id, 'icon', 32),
            ];

            if (count($results) >= 20) {
                break;
            }
        }

        return $results;
    }

    /**
     * Check if a T2 manufacturing blueprint has an invention chain.
     *
     * First checks if one of its materials is a T1 item whose blueprint has
     * an invention activity. If not found (e.g. T2 rigs), falls back to
     * checking if any invention activity produces the T2 blueprint directly.
     */
    protected function hasTechChain(BlueprintActivity $t2MfgActivity): bool
    {
        foreach ($t2MfgActivity->materials ?? [] as $material) {
            $matTypeId = $material['type_id'] ?? 0;
            if (! $matTypeId) {
                continue;
            }

            $matBlueprint = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
                ->whereProducesType($matTypeId)
                ->first();

            if (! $matBlueprint) {
                continue;
            }

            $hasInvention = BlueprintActivity::where('blueprint_type_id', $matBlueprint->blueprint_type_id)
                ->where('activity_id', BlueprintActivity::ACTIVITY_INVENTION)
                ->exists();

            if ($hasInvention) {
                return true;
            }
        }

        // Fallback: check if any invention activity produces the T2 blueprint
        return BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_INVENTION)
            ->whereProducesType($t2MfgActivity->blueprint_type_id)
            ->exists();
    }

    /**
     * Resolve the full invention chain for a T2 item.
     *
     * Returns all data needed for invention planning:
     * - T2 manufacturing data (blueprint, materials, products)
     * - T1 item identification and manufacturing data
     * - Invention datacores and base probability/runs
     */
    public function resolveInventionChain(int $t2ItemTypeId): ?array
    {
        // 1. Find T2 manufacturing blueprint
        $t2MfgActivity = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
            ->whereProducesType($t2ItemTypeId)
            ->first();

        if (! $t2MfgActivity) {
            return null;
        }

        $t2BlueprintTypeId = $t2MfgActivity->blueprint_type_id;
        $t2Manufacturing = [
            'materials' => $t2MfgActivity->materials ?? [],
            'products' => $t2MfgActivity->products ?? [],
            'time' => $t2MfgActivity->time ?? 0,
        ];

        // 2. Identify the T1 item from the T2 BOM
        $t1ItemTypeId = null;
        $t1ItemName = null;
        $t1BlueprintTypeId = null;

        foreach ($t2Manufacturing['materials'] as $material) {
            $matTypeId = $material['type_id'] ?? 0;
            if (! $matTypeId) {
                continue;
            }

            $matBlueprint = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
                ->whereProducesType($matTypeId)
                ->first();

            if (! $matBlueprint) {
                continue;
            }

            $hasInvention = BlueprintActivity::where('blueprint_type_id', $matBlueprint->blueprint_type_id)
                ->where('activity_id', BlueprintActivity::ACTIVITY_INVENTION)
                ->exists();

            if ($hasInvention) {
                $t1ItemTypeId = $matTypeId;
                $t1ItemName = $material['name'] ?? 'Unknown';
                $t1BlueprintTypeId = $matBlueprint->blueprint_type_id;
                break;
            }
        }

        if (! $t1ItemTypeId) {
            // Fallback: find the invention activity that produces the T2 blueprint.
            // This handles items like T2 rigs where the T1 variant is not a material.
            $inventionForT2 = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_INVENTION)
                ->whereProducesType($t2BlueprintTypeId)
                ->first();

            if ($inventionForT2) {
                $t1BlueprintTypeId = $inventionForT2->blueprint_type_id;

                // Find the T1 item produced by this T1 blueprint
                $t1MfgForItem = BlueprintActivity::where('blueprint_type_id', $t1BlueprintTypeId)
                    ->where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
                    ->first();

                if ($t1MfgForItem && ! empty($t1MfgForItem->products)) {
                    $t1Product = $t1MfgForItem->products[0];
                    $t1ItemTypeId = $t1Product['type_id'] ?? null;
                    $t1ItemName = $t1Product['name'] ?? 'Unknown';
                }
            }
        }

        if (! $t1ItemTypeId) {
            return null;
        }

        // 3. Get T1 manufacturing data
        $t1MfgActivity = BlueprintActivity::where('blueprint_type_id', $t1BlueprintTypeId)
            ->where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
            ->first();

        $t1Manufacturing = null;
        if ($t1MfgActivity) {
            $t1Manufacturing = [
                'materials' => $t1MfgActivity->materials ?? [],
                'products' => $t1MfgActivity->products ?? [],
                'time' => $t1MfgActivity->time ?? 0,
            ];
        }

        // 4. Get invention data (datacores) from T1 blueprint
        $inventionActivity = BlueprintActivity::where('blueprint_type_id', $t1BlueprintTypeId)
            ->where('activity_id', BlueprintActivity::ACTIVITY_INVENTION)
            ->first();

        $datacores = [];
        $inventionTime = 0;
        $scienceSkillIds = [];

        if ($inventionActivity) {
            $inventionTime = $inventionActivity->time ?? 0;
            foreach ($inventionActivity->materials ?? [] as $material) {
                $matTypeId = $material['type_id'] ?? 0;
                $skillId = self::DATACORE_TO_SKILL[$matTypeId] ?? null;

                $datacores[] = [
                    'type_id' => $matTypeId,
                    'name' => $material['name'] ?? 'Unknown',
                    'quantity' => $material['quantity'] ?? 1,
                    'skill_id' => $skillId,
                    'skill_name' => $skillId ? (self::DATACORE_SKILLS[$skillId] ?? 'Unknown') : null,
                ];

                if ($skillId) {
                    $scienceSkillIds[] = $skillId;
                }
            }
        }

        // 5. Resolve base probability, base runs, and encryption skill
        $baseProbability = 0.25;
        $baseRuns = 10;
        $encryptionSkillId = null;
        $encryptionSkillName = null;

        // Use local invention products for probability and base runs
        if ($inventionActivity && ! empty($inventionActivity->products)) {
            foreach ($inventionActivity->products as $product) {
                $prob = $product['probability'] ?? null;
                if ($prob !== null && (float) $prob > 0) {
                    $baseProbability = (float) $prob;
                }
                $runs = $product['quantity'] ?? null;
                if ($runs !== null && (int) $runs > 0) {
                    $baseRuns = (int) $runs;
                }
            }
        }

        // Use local invention skills to identify the encryption skill and science skills
        $scienceSkill1Id = null;
        $scienceSkill1Name = null;
        $scienceSkill2Id = null;
        $scienceSkill2Name = null;

        if ($inventionActivity && ! empty($inventionActivity->skills)) {
            $encryptionSkillIdMap = array_keys(self::ENCRYPTION_SKILLS);
            $scienceIdx = 0;

            // Batch-fetch names for any non-datacore, non-encryption skills
            $unknownSkillIds = [];
            foreach ($inventionActivity->skills as $skillEntry) {
                $skillTypeId = (int) ($skillEntry['type_id'] ?? 0);
                if ($skillTypeId && ! isset(self::ENCRYPTION_SKILLS[$skillTypeId]) && ! isset(self::DATACORE_SKILLS[$skillTypeId])) {
                    $unknownSkillIds[] = $skillTypeId;
                }
            }
            $unknownSkillNames = ! empty($unknownSkillIds)
                ? EveType::whereIn('type_id', $unknownSkillIds)->pluck('name', 'type_id')->all()
                : [];

            foreach ($inventionActivity->skills as $skillEntry) {
                $skillTypeId = (int) ($skillEntry['type_id'] ?? 0);
                if (! $skillTypeId) {
                    continue;
                }

                // Encryption skill
                if (in_array($skillTypeId, $encryptionSkillIdMap, true)) {
                    $encryptionSkillId = $skillTypeId;
                    $encryptionSkillName = self::ENCRYPTION_SKILLS[$skillTypeId];

                    continue;
                }

                // Science skills (datacore skills, racial ship skills, or any other non-encryption skill)
                if ($scienceIdx < 2) {
                    $skillName = self::DATACORE_SKILLS[$skillTypeId]
                        ?? $unknownSkillNames[$skillTypeId]
                        ?? 'Unknown Skill';

                    if ($scienceIdx === 0) {
                        $scienceSkill1Id = $skillTypeId;
                        $scienceSkill1Name = $skillName;
                    } else {
                        $scienceSkill2Id = $skillTypeId;
                        $scienceSkill2Name = $skillName;
                    }
                    $scienceIdx++;
                }
            }
        }

        // Fall back to datacore material mapping for science skills if skills field is empty
        if ($scienceSkill1Id === null && count($scienceSkillIds) >= 1) {
            $scienceSkill1Id = $scienceSkillIds[0];
            $scienceSkill1Name = self::DATACORE_SKILLS[$scienceSkill1Id] ?? null;
        }
        if ($scienceSkill2Id === null && count($scienceSkillIds) >= 2) {
            $scienceSkill2Id = $scienceSkillIds[1];
            $scienceSkill2Name = self::DATACORE_SKILLS[$scienceSkill2Id] ?? null;
        }

        // Fall back to Fuzzwork SDE API if local data is incomplete
        if ($baseProbability === 0.25 || $encryptionSkillId === null) {
            $sdeData = $this->esi->getBlueprintSdeData($t1BlueprintTypeId);
            if ($sdeData) {
                // Base probability and runs from decryptors (the "None" entry)
                if ($baseProbability === 0.25 && ! empty($sdeData['decryptors'])) {
                    foreach ($sdeData['decryptors'] as $dec) {
                        $decId = (int) ($dec['decryptorID'] ?? $dec['typeid'] ?? -1);
                        if ($decId === 0 || $decId === -1) {
                            $baseProbability = (float) ($dec['probability'] ?? $baseProbability);
                            $baseRuns = (int) ($dec['runs'] ?? $baseRuns);
                            break;
                        }
                    }
                }

                // Identify the specific encryption skill from invention activity skills
                if ($encryptionSkillId === null) {
                    $inventionSkills = $sdeData['blueprintSkills']['8'] ?? $sdeData['blueprintSkills'][8] ?? [];
                    $encryptionSkillIdMap = array_keys(self::ENCRYPTION_SKILLS);
                    foreach ($inventionSkills as $skillEntry) {
                        $skillTypeId = (int) ($skillEntry['typeid'] ?? $skillEntry['typeID'] ?? $skillEntry['type_id'] ?? 0);
                        if ($skillTypeId && in_array($skillTypeId, $encryptionSkillIdMap, true)) {
                            $encryptionSkillId = $skillTypeId;
                            $encryptionSkillName = self::ENCRYPTION_SKILLS[$skillTypeId];
                            break;
                        }
                    }
                }
            }
        }

        return [
            't2_item_type_id' => $t2ItemTypeId,
            't2_blueprint_type_id' => $t2BlueprintTypeId,
            't2_manufacturing' => $t2Manufacturing,
            't1_item_type_id' => $t1ItemTypeId,
            't1_item_name' => $t1ItemName,
            't1_blueprint_type_id' => $t1BlueprintTypeId,
            't1_manufacturing' => $t1Manufacturing,
            'datacores' => $datacores,
            'invention_time' => $inventionTime,
            'base_probability' => $baseProbability,
            'base_runs' => $baseRuns,
            'science_skill_ids' => $scienceSkillIds,
            'encryption_skill_id' => $encryptionSkillId,
            'encryption_skill_name' => $encryptionSkillName,
            'science1_skill_id' => $scienceSkill1Id,
            'science1_skill_name' => $scienceSkill1Name,
            'science2_skill_id' => $scienceSkill2Id,
            'science2_skill_name' => $scienceSkill2Name,
        ];
    }

    /**
     * Calculate invention probability.
     *
     * Formula: P = base × (1 + encryption/40 + science1/30 + science2/30) × decryptor_multiplier
     *
     * Encryption skill divides by 40, datacore science skills divide by 30.
     */
    public function calculateInventionProbability(
        float $baseProbability,
        int $encryptionSkillLevel,
        int $science1SkillLevel,
        int $science2SkillLevel,
        float $decryptorMultiplier = 1.0
    ): float {
        $skillModifier = 1.0
            + ($encryptionSkillLevel / 40.0)
            + ($science1SkillLevel / 30.0)
            + ($science2SkillLevel / 30.0);

        $probability = $baseProbability * $skillModifier * $decryptorMultiplier;

        return min(1.0, max(0.0, round($probability, 6)));
    }

    /**
     * Calculate the output T2 BPC specifications based on decryptor.
     *
     * Base values: ME 2, TE 4. Decryptor modifiers are added.
     * ME is clamped to 0-10, TE to 0-20, runs minimum 1.
     */
    public function calculateT2Bpc(int $baseRuns, string $decryptorKey = 'none'): array
    {
        $decryptor = self::DECRYPTORS[$decryptorKey] ?? self::DECRYPTORS['none'];

        $me = max(self::MIN_ME, min(self::MAX_ME, self::BASE_ME + $decryptor['me_modifier']));
        $te = max(self::MIN_TE, min(self::MAX_TE, self::BASE_TE + $decryptor['te_modifier']));
        $runs = max(1, $baseRuns + $decryptor['run_modifier']);

        return [
            'me' => $me,
            'te' => $te,
            'runs' => $runs,
            'decryptor' => $decryptor,
        ];
    }

    /**
     * Calculate manufacturing materials with ME bonus.
     * Delegates to ManufacturingCalculatorService.
     */
    public function calculateMaterials(array $baseMaterials, int $meLevel, int $runs, float $structureMeBonus = 0.0): array
    {
        return $this->calculator->calculateMaterials($baseMaterials, $meLevel, $runs, $structureMeBonus);
    }

    /**
     * Resolve build data for each T2 component material.
     *
     * For each material in the T2 BOM, checks if there is a manufacturing blueprint.
     * If so, returns the sub-materials, base time, and calculated material quantities.
     *
     * @param  array  $t2Materials  Calculated T2 materials (with adjusted_quantity)
     * @param  int  $meLevel  ME level to use for sub-component manufacturing
     * @param  float  $structureMeBonus  Structure ME bonus for sub-component manufacturing
     * @param  float|null  $reactionMeBonus  Structure ME bonus for reaction sub-components (uses $structureMeBonus if null)
     * @return array  Keyed by type_id => component build data or null
     */
    public function resolveComponentBuildData(array $t2Materials, int $meLevel = 0, float $structureMeBonus = 0.0, ?float $reactionMeBonus = null): array
    {
        $components = [];
        $effectiveReactionMeBonus = $reactionMeBonus ?? $structureMeBonus;

        foreach ($t2Materials as $material) {
            $typeId = $material['type_id'] ?? 0;
            if (! $typeId) {
                continue;
            }

            // Check if this material has a manufacturing blueprint
            $activity = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
                ->whereProducesType($typeId)
                ->first();

            // If no manufacturing blueprint, check for reaction formula
            if (! $activity) {
                $activity = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_REACTION)
                    ->whereProducesType($typeId)
                    ->first();
            }

            if (! $activity) {
                $components[$typeId] = null;

                continue;
            }

            $isReaction = $activity->activity_id === BlueprintActivity::ACTIVITY_REACTION;

            $portionSize = 1;
            foreach ($activity->products ?? [] as $product) {
                if (($product['type_id'] ?? 0) === $typeId) {
                    $portionSize = max(1, (int) ($product['quantity'] ?? 1));
                    break;
                }
            }

            // Calculate how many runs are needed to produce the required quantity
            $requiredQty = $material['adjusted_quantity'] ?? 0;
            $runsNeeded = $portionSize > 0 ? (int) ceil($requiredQty / $portionSize) : $requiredQty;

            // Reactions use ME 0
            $effectiveMe = $isReaction ? 0 : $meLevel;

            // Use reaction structure bonus for reaction activities, manufacturing bonus otherwise
            $effectiveBonus = $isReaction ? $effectiveReactionMeBonus : $structureMeBonus;

            // Calculate sub-materials for this component
            $subMaterials = $this->calculator->calculateMaterials(
                $activity->materials ?? [],
                $effectiveMe,
                $runsNeeded,
                $effectiveBonus
            );

            $components[$typeId] = [
                'blueprint_type_id' => $activity->blueprint_type_id,
                'is_buildable' => true,
                'is_reaction' => $isReaction,
                'portion_size' => $portionSize,
                'runs_needed' => $runsNeeded,
                'base_time' => $activity->time ?? 0,
                'materials' => $subMaterials,
            ];
        }

        return $components;
    }

    /**
     * Recursively resolve the full manufacturing/reaction chain for a set of materials.
     *
     * For each material, checks if it can be manufactured or produced via reaction.
     * If so, adds it as a production step and recursively resolves its sub-materials.
     * Returns a nested tree of production steps where each step's children are its
     * sub-component production steps.
     *
     * @param  array  $materials  Materials to resolve (with type_id, name, adjusted_quantity)
     * @param  int  $meLevel  ME level to assume for sub-component blueprints
     * @param  float  $structureMeBonus  Structure ME bonus
     * @param  array  $visited  Type IDs already visited (prevents infinite loops)
     * @param  float|null  $reactionMeBonus  Structure ME bonus for reaction activities (uses $structureMeBonus if null)
     * @return array  Nested tree of production steps with children
     */
    public function resolveFullMaterialChain(array $materials, int $meLevel = 10, float $structureMeBonus = 0.0, array $visited = [], ?float $reactionMeBonus = null): array
    {
        $steps = [];
        $effectiveReactionMeBonus = $reactionMeBonus ?? $structureMeBonus;

        // Batch-fetch group_ids to identify fuel blocks without N+1 queries
        $materialTypeIds = array_filter(array_column($materials, 'type_id'));
        $groupMap = ! empty($materialTypeIds)
            ? EveType::whereIn('type_id', $materialTypeIds)->pluck('group_id', 'type_id')->all()
            : [];

        foreach ($materials as $material) {
            $typeId = $material['type_id'] ?? 0;
            if (! $typeId || in_array($typeId, $visited)) {
                continue;
            }

            // Fuel blocks are treated as raw materials — skip blueprint resolution
            if (($groupMap[$typeId] ?? null) === AssetService::FUEL_BLOCK_GROUP_ID) {
                continue;
            }

            // Check for manufacturing blueprint
            $activity = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
                ->whereProducesType($typeId)
                ->first();

            // If no manufacturing blueprint, check for reaction formula
            if (! $activity) {
                $activity = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_REACTION)
                    ->whereProducesType($typeId)
                    ->first();
            }

            if (! $activity) {
                continue;
            }

            $isReaction = $activity->activity_id === BlueprintActivity::ACTIVITY_REACTION;

            $portionSize = 1;
            foreach ($activity->products ?? [] as $product) {
                if (($product['type_id'] ?? 0) === $typeId) {
                    $portionSize = max(1, (int) ($product['quantity'] ?? 1));
                    break;
                }
            }

            $requiredQty = $material['adjusted_quantity'] ?? $material['quantity'] ?? 0;
            $runsNeeded = $portionSize > 0 ? (int) ceil($requiredQty / $portionSize) : $requiredQty;

            // Reactions use ME 0
            $effectiveMe = $isReaction ? 0 : $meLevel;

            // Use reaction structure bonus for reaction activities, manufacturing bonus otherwise
            $effectiveBonus = $isReaction ? $effectiveReactionMeBonus : $structureMeBonus;

            $subMaterials = $this->calculator->calculateMaterials(
                $activity->materials ?? [],
                $effectiveMe,
                $runsNeeded,
                $effectiveBonus
            );

            // Recursively resolve sub-materials into nested children
            $children = $this->resolveFullMaterialChain(
                $subMaterials,
                $meLevel,
                $structureMeBonus,
                array_merge($visited, [$typeId]),
                $reactionMeBonus
            );

            $steps[] = [
                'type_id' => $typeId,
                'name' => $material['name'] ?? 'Unknown',
                'quantity_required' => $requiredQty,
                'runs_needed' => $runsNeeded,
                'portion_size' => $portionSize,
                'activity_type' => $isReaction ? 'reaction' : 'manufacturing',
                'blueprint_type_id' => $activity->blueprint_type_id,
                'materials' => $subMaterials,
                'children' => $children,
            ];
        }

        return $steps;
    }

    /**
     * Get the list of available decryptors.
     */
    public static function getDecryptors(): array
    {
        return self::DECRYPTORS;
    }
}
