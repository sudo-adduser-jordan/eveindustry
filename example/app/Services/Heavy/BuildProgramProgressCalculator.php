<?php

namespace App\Services\Heavy;

use App\Models\EveType;
use App\Services\AssetService;

/**
 * The progress maths shared by the personal and corporate build-program
 * progress endpoints (old /api/... routes and /api/v1).
 *
 * Materials are checked against $inputQuantities and finished products
 * against $outputQuantities. Personal programs pass the same map for both.
 */
class BuildProgramProgressCalculator
{
    /**
     * Everything the progress response carries except the owner's names.
     * Key order is the old routes' response order; `sections` comes last and
     * only when the program has sections.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $settings
     * @param  array<int, int|float>  $inputQuantities  type_id => quantity
     * @param  array<int, int|float>  $outputQuantities  type_id => quantity
     * @return array<string, mixed>
     */
    public function summarise(array $steps, array $settings, array $inputQuantities, array $outputQuantities): array
    {
        $result = $this->calculateNestedProgress($steps, $inputQuantities, $outputQuantities);

        // Per-section progress if sections metadata exists.
        $sections = $settings['sections'] ?? [];
        $sectionProgress = [];
        if (! empty($sections)) {
            $stepIndex = 0;
            foreach ($sections as $section) {
                $sectionSteps = array_slice($steps, $stepIndex, $section['step_count']);
                $sectionResult = $this->calculateNestedProgress($sectionSteps, $inputQuantities, $outputQuantities);
                $sectionProgress[] = [
                    'name' => $section['name'],
                    'step_count' => $section['step_count'],
                    'overall_progress' => $sectionResult['overall_progress'],
                ];
                $stepIndex += $section['step_count'];
            }
        }

        // Group ids for all material type_ids, to identify fuel blocks.
        $allMatTypeIds = $this->collectAllMaterialTypeIds($steps);
        $typeGroupMap = ! empty($allMatTypeIds)
            ? EveType::whereIn('type_id', array_keys($allMatTypeIds))->pluck('group_id', 'type_id')->all()
            : [];

        $blueprints = [];
        $baseMaterials = [];
        $fuelBlocks = [];
        $this->collectSummaryData($steps, $inputQuantities, $outputQuantities, $blueprints, $baseMaterials, $fuelBlocks, $typeGroupMap);

        if (! empty($blueprints)) {
            $bpNames = EveType::whereIn('type_id', array_keys($blueprints))
                ->pluck('name', 'type_id')
                ->all();
            foreach ($blueprints as $bpTypeId => &$bp) {
                $bp['name'] = $bpNames[$bpTypeId] ?? 'Unknown Blueprint';
            }
            unset($bp);
        }

        if (! empty($baseMaterials)) {
            $matTypeData = EveType::whereIn('type_id', array_keys($baseMaterials))
                ->get(['type_id', 'group_id', 'volume', 'packaged_volume'])
                ->keyBy('type_id');
            foreach ($baseMaterials as $matTypeId => &$mat) {
                $eveType = $matTypeData->get($matTypeId);
                $groupId = $eveType->group_id ?? null;
                $mat['group_name'] = ($groupId !== null && isset(AssetService::MATERIAL_GROUPS[$groupId]))
                    ? AssetService::MATERIAL_GROUPS[$groupId]
                    : 'Other';
                $mat['volume'] = $eveType ? ($eveType->packaged_volume ?? $eveType->volume ?? 0) : 0;
            }
            unset($mat);
        }

        if (! empty($fuelBlocks)) {
            $fuelTypeData = EveType::whereIn('type_id', array_keys($fuelBlocks))
                ->get(['type_id', 'volume', 'packaged_volume'])
                ->keyBy('type_id');
            foreach ($fuelBlocks as $fbTypeId => &$fb) {
                $eveType = $fuelTypeData->get($fbTypeId);
                $fb['volume'] = $eveType ? ($eveType->packaged_volume ?? $eveType->volume ?? 0) : 0;
            }
            unset($fb);
        }

        // Collect reactions summary data
        $reactionStepsData = [];
        $this->collectReactionSummaryData($steps, $outputQuantities, 1.0, $reactionStepsData);
        $reactionsSummary = [];
        if (! empty($reactionStepsData)) {
            $reactionVolTypeIds = [];
            foreach ($reactionStepsData as $r) {
                $reactionVolTypeIds[$r['type_id']] = true;
                foreach ($r['material_qtys'] as $matTypeId => $qty) {
                    $reactionVolTypeIds[$matTypeId] = true;
                }
            }

            $reactionVolumes = EveType::whereIn('type_id', array_keys($reactionVolTypeIds))
                ->get(['type_id', 'volume', 'packaged_volume'])
                ->mapWithKeys(fn ($t) => [$t->type_id => $t->packaged_volume ?? $t->volume ?? 0])
                ->all();

            foreach ($reactionStepsData as $r) {
                $inputVol = 0.0;
                $inputs = [];
                foreach ($r['material_qtys'] as $matTypeId => $qty) {
                    $inputVol += $qty * ($reactionVolumes[$matTypeId] ?? 0);
                    $inputs[] = [
                        'type_id' => $matTypeId,
                        'name' => $r['material_names'][$matTypeId] ?? 'Unknown',
                        'required_qty' => $qty,
                        'available' => $inputQuantities[$matTypeId] ?? 0,
                    ];
                }
                $unitVol = $reactionVolumes[$r['type_id']] ?? 0;
                $reactionsSummary[] = [
                    'type_id' => $r['type_id'],
                    'name' => $r['name'],
                    'blueprint_type_id' => $r['blueprint_type_id'],
                    'total_runs' => $r['total_runs'],
                    'output_qty' => $r['output_qty'],
                    'available' => $r['available'],
                    'net_output_qty' => $r['net_output_qty'],
                    'net_runs' => $r['net_runs'],
                    'input_volume' => round($inputVol, 2),
                    'output_volume' => round($r['net_output_qty'] * $unitVol, 2),
                    'inputs' => $inputs,
                ];
            }
        }

        $response = [
            'overall_progress' => $result['overall_progress'],
            'steps' => $result['steps'],
            'required_blueprints' => array_values($blueprints),
            'base_materials' => array_values($baseMaterials),
            'fuel_blocks' => array_values($fuelBlocks),
            'reactions_summary' => $reactionsSummary,
        ];

        if (! empty($sectionProgress)) {
            $response['sections'] = $sectionProgress;
        }

        return $response;
    }

    /**
     * Recursively calculate progress for nested steps with separate input/output asset maps.
     *
     * @param  array  $inputQuantities  Asset quantities at input locations (for materials)
     * @param  array  $outputQuantities  Asset quantities at output locations (for finished products)
     * @param  float  $parentScale  Scaling factor inherited from the parent step
     */
    private function calculateNestedProgress(
        array $steps,
        array $inputQuantities,
        array $outputQuantities,
        float $parentScale = 1.0,
    ): array {
        $stepsProgress = [];
        $totalRequired = 0;
        $totalAvailable = 0;

        foreach ($steps as $step) {
            $typeId = $step['type_id'] ?? 0;
            $storedRequired = $step['quantity_required'] ?? 0;

            $required = $parentScale < 1.0
                ? (int) ceil($storedRequired * $parentScale)
                : $storedRequired;

            // Finished products are checked in the output hangar.
            $available = $outputQuantities[$typeId] ?? 0;
            $progress = $required > 0 ? min(100, round(($available / $required) * 100, 1)) : 100;
            $hasEnough = $available >= $required;

            $remaining = max(0, $required - $available);
            $childScale = $storedRequired > 0 ? $remaining / $storedRequired : 0.0;

            $materialsProgress = [];
            foreach ($step['materials'] ?? [] as $mat) {
                $matTypeId = $mat['type_id'] ?? 0;
                $matStored = $mat['adjusted_quantity'] ?? $mat['quantity'] ?? 0;

                $matRequired = $childScale < 1.0
                    ? (int) ceil($matStored * $childScale)
                    : $matStored;

                // Raw materials are checked in the input hangar.
                $matAvailable = $inputQuantities[$matTypeId] ?? 0;
                $matProgress = $matRequired > 0 ? min(100, round(($matAvailable / $matRequired) * 100, 1)) : 100;

                $materialsProgress[] = [
                    'type_id' => $matTypeId,
                    'name' => $mat['name'] ?? 'Unknown',
                    'required' => $matRequired,
                    'available' => $matAvailable,
                    'progress' => $matProgress,
                    'has_enough' => $matAvailable >= $matRequired,
                ];
            }

            $childrenResult = $this->calculateNestedProgress(
                $step['children'] ?? [],
                $inputQuantities,
                $outputQuantities,
                $childScale
            );

            $stepsProgress[] = [
                'type_id' => $typeId,
                'name' => $step['name'] ?? 'Unknown',
                'quantity_required' => $required,
                'available' => $available,
                'progress' => $progress,
                'has_enough' => $hasEnough,
                'materials' => $materialsProgress,
                'children' => $childrenResult['steps'],
            ];

            $totalRequired += $required;
            $totalAvailable += min($available, $required);
            $totalRequired += $childrenResult['total_required'];
            $totalAvailable += $childrenResult['total_available'];
        }

        $overallProgress = $totalRequired > 0 ? round(($totalAvailable / $totalRequired) * 100, 1) : 100;

        return [
            'steps' => $stepsProgress,
            'overall_progress' => $overallProgress,
            'total_required' => $totalRequired,
            'total_available' => $totalAvailable,
        ];
    }

    /**
     * Recursively collect required blueprints/formulas, base materials, and fuel blocks from steps.
     *
     * @param  float  $parentScale  Scaling factor inherited from the parent step.
     */
    private function collectSummaryData(
        array $steps,
        array $inputQuantities,
        array $outputQuantities,
        array &$blueprints,
        array &$baseMaterials,
        array &$fuelBlocks,
        array $typeGroupMap,
        float $parentScale = 1.0,
    ): void {
        foreach ($steps as $step) {
            $blueprintTypeId = $step['blueprint_type_id'] ?? 0;
            $activityType = $step['activity_type'] ?? null;
            $typeId = $step['type_id'] ?? 0;
            $storedRequired = $step['quantity_required'] ?? 0;

            $effectiveRequired = $parentScale < 1.0
                ? (int) ceil($storedRequired * $parentScale)
                : $storedRequired;

            $available = $outputQuantities[$typeId] ?? 0;
            $remaining = max(0, $effectiveRequired - $available);
            $childScale = $storedRequired > 0 ? $remaining / $storedRequired : 0.0;

            if ($blueprintTypeId && $activityType) {
                if (! isset($blueprints[$blueprintTypeId])) {
                    $blueprints[$blueprintTypeId] = [
                        'type_id' => $blueprintTypeId,
                        'name' => '',
                        'activity_type' => $activityType,
                        'owned' => ($inputQuantities[$blueprintTypeId] ?? 0) > 0,
                    ];
                }
            } else {
                $stepQty = $effectiveRequired;
                if ($typeId && $stepQty > 0) {
                    $groupId = $typeGroupMap[$typeId] ?? null;
                    if ($groupId === AssetService::FUEL_BLOCK_GROUP_ID) {
                        if (isset($fuelBlocks[$typeId])) {
                            $fuelBlocks[$typeId]['quantity'] += $stepQty;
                        } else {
                            $fuelBlocks[$typeId] = [
                                'type_id' => $typeId,
                                'name' => $step['name'] ?? 'Unknown',
                                'quantity' => $stepQty,
                                'available' => $inputQuantities[$typeId] ?? 0,
                                'group_name' => 'Fuel Blocks',
                            ];
                        }
                    } else {
                        if (isset($baseMaterials[$typeId])) {
                            $baseMaterials[$typeId]['quantity'] += $stepQty;
                        } else {
                            $baseMaterials[$typeId] = [
                                'type_id' => $typeId,
                                'name' => $step['name'] ?? 'Unknown',
                                'quantity' => $stepQty,
                                'available' => $inputQuantities[$typeId] ?? 0,
                            ];
                        }
                    }
                }
            }

            $childTypeIds = [];
            foreach ($step['children'] ?? [] as $child) {
                $childTypeIds[$child['type_id'] ?? 0] = true;
            }

            foreach ($step['materials'] ?? [] as $mat) {
                $matTypeId = $mat['type_id'] ?? 0;
                if (! $matTypeId) {
                    continue;
                }

                $matStored = $mat['adjusted_quantity'] ?? $mat['quantity'] ?? 0;
                $matQty = $childScale < 1.0
                    ? (int) ceil($matStored * $childScale)
                    : $matStored;
                $groupId = $typeGroupMap[$matTypeId] ?? null;

                if ($groupId === AssetService::FUEL_BLOCK_GROUP_ID) {
                    if (isset($fuelBlocks[$matTypeId])) {
                        $fuelBlocks[$matTypeId]['quantity'] += $matQty;
                    } else {
                        $fuelBlocks[$matTypeId] = [
                            'type_id' => $matTypeId,
                            'name' => $mat['name'] ?? 'Unknown',
                            'quantity' => $matQty,
                            'available' => $inputQuantities[$matTypeId] ?? 0,
                            'group_name' => 'Fuel Blocks',
                        ];
                    }

                    continue;
                }

                if (isset($childTypeIds[$matTypeId])) {
                    continue;
                }

                if (isset($baseMaterials[$matTypeId])) {
                    $baseMaterials[$matTypeId]['quantity'] += $matQty;
                } else {
                    $baseMaterials[$matTypeId] = [
                        'type_id' => $matTypeId,
                        'name' => $mat['name'] ?? 'Unknown',
                        'quantity' => $matQty,
                        'available' => $inputQuantities[$matTypeId] ?? 0,
                    ];
                }
            }

            foreach ($step['children'] ?? [] as $child) {
                $childTypeId = $child['type_id'] ?? 0;
                $childGroupId = $typeGroupMap[$childTypeId] ?? null;
                if ($childGroupId === AssetService::FUEL_BLOCK_GROUP_ID) {
                    continue;
                }
                $this->collectSummaryData([$child], $inputQuantities, $outputQuantities, $blueprints, $baseMaterials, $fuelBlocks, $typeGroupMap, $childScale);
            }
        }
    }

    /**
     * Recursively collect all material type_ids from the step tree.
     *
     * @return array<int, true>
     */
    private function collectAllMaterialTypeIds(array $steps): array
    {
        $typeIds = [];
        foreach ($steps as $step) {
            $blueprintTypeId = $step['blueprint_type_id'] ?? 0;
            if (! $blueprintTypeId) {
                $stepTypeId = $step['type_id'] ?? 0;
                if ($stepTypeId) {
                    $typeIds[$stepTypeId] = true;
                }
            }

            foreach ($step['materials'] ?? [] as $mat) {
                $typeId = $mat['type_id'] ?? 0;
                if ($typeId) {
                    $typeIds[$typeId] = true;
                }
            }
            foreach ($step['children'] ?? [] as $child) {
                $childTypeId = $child['type_id'] ?? 0;
                if ($childTypeId) {
                    $typeIds[$childTypeId] = true;
                }
            }
            if (! empty($step['children'])) {
                $typeIds += $this->collectAllMaterialTypeIds($step['children']);
            }
        }

        return $typeIds;
    }

    /**
     * Recursively collect reaction step data for the reactions summary.
     *
     * Uses output quantities (finished-product hangar) to determine in-stock
     * amounts for corporate build programs.
     *
     * @param  array  $reactions  Accumulator keyed by blueprint_type_id.
     */
    private function collectReactionSummaryData(
        array $steps,
        array $outputQuantities,
        float $parentScale = 1.0,
        array &$reactions = [],
    ): void {
        foreach ($steps as $step) {
            $activityType = $step['activity_type'] ?? null;
            $typeId = $step['type_id'] ?? 0;
            $storedRequired = $step['quantity_required'] ?? 0;
            $runsNeeded = (int) ($step['runs_needed'] ?? 0);
            $blueprintTypeId = $step['blueprint_type_id'] ?? 0;

            $effectiveRequired = $parentScale < 1.0
                ? (int) ceil($storedRequired * $parentScale)
                : $storedRequired;

            if ($activityType === 'reaction' && $blueprintTypeId) {
                $available = $outputQuantities[$typeId] ?? 0;
                $remaining = max(0, $effectiveRequired - $available);

                $effectiveRuns = ($storedRequired > 0 && $runsNeeded > 0)
                    ? (int) ceil($runsNeeded * ($effectiveRequired / $storedRequired))
                    : $runsNeeded;

                // Portion size: units produced per run, inferred from stored totals.
                // round() handles floating-point noise from the integer division
                // (stored_required = runs * portion_size, so result is always a whole number).
                $portionSize = ($runsNeeded > 0 && $storedRequired > 0)
                    ? max(1, (int) round($storedRequired / $runsNeeded))
                    : 1;

                $netRuns = $portionSize > 0 ? (int) ceil($remaining / $portionSize) : 0;

                $materialQtys = [];
                $materialNames = [];
                foreach ($step['materials'] ?? [] as $mat) {
                    $matTypeId = $mat['type_id'] ?? 0;
                    if (! $matTypeId) {
                        continue;
                    }
                    $matStored = $mat['adjusted_quantity'] ?? $mat['quantity'] ?? 0;
                    $matQty = $storedRequired > 0
                        ? (int) ceil($matStored * ($effectiveRequired / $storedRequired))
                        : $matStored;
                    $materialQtys[$matTypeId] = ($materialQtys[$matTypeId] ?? 0) + $matQty;
                    $materialNames[$matTypeId] = $mat['name'] ?? 'Unknown';
                }

                if (isset($reactions[$blueprintTypeId])) {
                    $reactions[$blueprintTypeId]['total_runs'] += $effectiveRuns;
                    $reactions[$blueprintTypeId]['output_qty'] += $effectiveRequired;
                    $reactions[$blueprintTypeId]['net_output_qty'] += $remaining;
                    $reactions[$blueprintTypeId]['net_runs'] += $netRuns;
                    foreach ($materialQtys as $matTypeId => $qty) {
                        $reactions[$blueprintTypeId]['material_qtys'][$matTypeId] =
                            ($reactions[$blueprintTypeId]['material_qtys'][$matTypeId] ?? 0) + $qty;
                        $reactions[$blueprintTypeId]['material_names'][$matTypeId] = $materialNames[$matTypeId];
                    }
                } else {
                    $reactions[$blueprintTypeId] = [
                        'type_id' => $typeId,
                        'name' => $step['name'] ?? 'Unknown',
                        'blueprint_type_id' => $blueprintTypeId,
                        'total_runs' => $effectiveRuns,
                        'output_qty' => $effectiveRequired,
                        'available' => $available,
                        'net_output_qty' => $remaining,
                        'net_runs' => $netRuns,
                        'material_qtys' => $materialQtys,
                        'material_names' => $materialNames,
                    ];
                }
            }

            if (! empty($step['children'])) {
                $available = $outputQuantities[$typeId] ?? 0;
                $remaining = max(0, $effectiveRequired - $available);
                $childScale = $storedRequired > 0 ? $remaining / $storedRequired : 0.0;
                $this->collectReactionSummaryData($step['children'], $outputQuantities, $childScale, $reactions);
            }
        }
    }
}
