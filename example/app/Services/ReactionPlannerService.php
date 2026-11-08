<?php

namespace App\Services;

use App\Models\BlueprintActivity;

class ReactionPlannerService
{
    /**
     * In-memory cache for resolved reaction lookups to avoid repeated DB queries.
     */
    private array $reactionCache = [];

    public function __construct(
        protected EsiService $esi,
        protected ReactionsCalculatorService $calculator
    ) {}

    /**
     * Resolve the full reaction chain for a list of planned composites.
     *
     * Each entry: [
     *   'formula_type_id' => int,
     *   'slots' => int,
     *   'runs_per_slot' => int,
     *   'rig_bonus' => float,
     * ]
     *
     * Returns the resolved chain with aggregated raw materials and intermediate slots.
     */
    public function resolveChain(array $entries, float $rigBonus = 0.0): array
    {
        $compositeSlots = [];
        $intermediateSlots = [];
        $rawMaterials = [];
        $compositeOutputs = [];

        foreach ($entries as $entry) {
            $formulaTypeId = $entry['formula_type_id'];
            $slots = $entry['slots'] ?? 1;
            $runsPerSlot = $entry['runs_per_slot'] ?? 240;
            $entryRigBonus = $entry['rig_bonus'] ?? $rigBonus;

            // Get the composite reaction data (materials, products, time)
            $sdeData = $this->esi->resolveBlueprintActivity($formulaTypeId, 11);
            if (! $sdeData || empty($sdeData['materials'])) {
                continue;
            }

            $totalRuns = $slots * $runsPerSlot;

            // Calculate composite output
            $portionSize = 1;
            $productName = 'Unknown';
            $productTypeId = null;
            if (! empty($sdeData['products'])) {
                $portionSize = $sdeData['products'][0]['quantity'] ?? 1;
                $productName = $sdeData['products'][0]['name'] ?? 'Unknown';
                $productTypeId = $sdeData['products'][0]['type_id'] ?? null;
            }

            $compositeOutputs[] = [
                'formula_type_id' => $formulaTypeId,
                'product_type_id' => $productTypeId,
                'product_name' => $productName,
                'slots' => $slots,
                'runs_per_slot' => $runsPerSlot,
                'total_runs' => $totalRuns,
                'total_output' => $totalRuns * $portionSize,
                'portion_size' => $portionSize,
            ];

            // Track composite slots
            $compositeSlots[] = [
                'formula_type_id' => $formulaTypeId,
                'product_name' => $productName,
                'slots' => $slots,
            ];

            // Calculate adjusted materials for the composite reaction
            $materials = $this->calculator->calculateMaterials(
                $sdeData['materials'],
                $totalRuns,
                $entryRigBonus
            );

            // For each material, determine if it's an intermediate (has its own reaction) or raw goo
            foreach ($materials as $material) {
                $materialTypeId = $material['type_id'];
                $materialReaction = $this->findReactionForProduct($materialTypeId);

                if ($materialReaction) {
                    // This is an intermediate - resolve its sub-chain
                    $subResult = $this->resolveIntermediate(
                        $materialReaction,
                        $materialTypeId,
                        $material['adjusted_quantity'],
                        $runsPerSlot,
                        $entryRigBonus
                    );

                    // Add intermediate slots
                    foreach ($subResult['slots'] as $slot) {
                        $this->addToSlots($intermediateSlots, $slot);
                    }

                    // Add raw materials from intermediate
                    foreach ($subResult['raw_materials'] as $raw) {
                        $this->addToRawMaterials($rawMaterials, $raw);
                    }
                } else {
                    // This is a raw material (moon goo or fuel block)
                    $this->addToRawMaterials($rawMaterials, [
                        'type_id' => $materialTypeId,
                        'name' => $material['name'],
                        'quantity' => $material['adjusted_quantity'],
                    ]);
                }
            }
        }

        // Sort raw materials by name for clean display
        usort($rawMaterials, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $totalSlots = array_sum(array_column($compositeSlots, 'slots'))
            + array_sum(array_column($intermediateSlots, 'slots'));

        return [
            'composite_outputs' => $compositeOutputs,
            'composite_slots' => $compositeSlots,
            'intermediate_slots' => $intermediateSlots,
            'raw_materials' => $rawMaterials,
            'total_composite_slots' => array_sum(array_column($compositeSlots, 'slots')),
            'total_intermediate_slots' => array_sum(array_column($intermediateSlots, 'slots')),
            'total_slots' => $totalSlots,
        ];
    }

    /**
     * Resolve an intermediate reaction to determine its raw material needs.
     */
    protected function resolveIntermediate(
        array $reaction,
        int $productTypeId,
        int $requiredQuantity,
        int $runsPerSlot,
        float $rigBonus
    ): array {
        $slots = [];
        $rawMaterials = [];

        // Determine how many runs of the intermediate are needed
        $portionSize = 1;
        if (! empty($reaction['products'])) {
            foreach ($reaction['products'] as $product) {
                if ($product['type_id'] === $productTypeId) {
                    $portionSize = $product['quantity'] ?? 1;
                    break;
                }
            }
        }

        $totalRunsNeeded = (int) ceil($requiredQuantity / $portionSize);

        // Calculate how many slots at the given runs_per_slot (for planning display)
        $slotsNeeded = max(1, (int) ceil($totalRunsNeeded / $runsPerSlot));

        // Get intermediate product name
        $intermediateName = 'Unknown';
        if (! empty($reaction['products'])) {
            foreach ($reaction['products'] as $product) {
                if ($product['type_id'] === $productTypeId) {
                    $intermediateName = $product['name'] ?? 'Unknown';
                    break;
                }
            }
        }

        $slots[] = [
            'formula_type_id' => $reaction['blueprint_type_id'],
            'product_type_id' => $productTypeId,
            'product_name' => $intermediateName,
            'slots' => $slotsNeeded,
            'runs_per_slot' => $slotsNeeded > 0 ? (int) ceil($totalRunsNeeded / $slotsNeeded) : $totalRunsNeeded,
            'total_runs' => $totalRunsNeeded,
        ];

        // Calculate raw materials for the actual runs needed (not rounded to slot boundaries)
        if (! empty($reaction['materials'])) {
            $materials = $this->calculator->calculateMaterials(
                $reaction['materials'],
                $totalRunsNeeded,
                $rigBonus
            );

            foreach ($materials as $material) {
                $this->addToRawMaterials($rawMaterials, [
                    'type_id' => $material['type_id'],
                    'name' => $material['name'],
                    'quantity' => $material['adjusted_quantity'],
                ]);
            }
        }

        return [
            'slots' => $slots,
            'raw_materials' => $rawMaterials,
        ];
    }

    /**
     * Find a reaction formula that produces the given product type ID.
     * Returns the SDE reaction data or null if not found (i.e., it's a raw material).
     * Results are cached in-memory to avoid N+1 queries during chain resolution.
     */
    public function findReactionForProduct(int $productTypeId): ?array
    {
        if (array_key_exists($productTypeId, $this->reactionCache)) {
            return $this->reactionCache[$productTypeId];
        }

        // Check blueprint_activities for a reaction that produces this type
        $activity = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_REACTION)
            ->whereProducesType($productTypeId)
            ->first();

        if (! $activity) {
            $this->reactionCache[$productTypeId] = null;

            return null;
        }

        $result = [
            'blueprint_type_id' => $activity->blueprint_type_id,
            'materials' => $activity->materials ?? [],
            'products' => $activity->products ?? [],
            'time' => $activity->time ?? 0,
        ];

        $this->reactionCache[$productTypeId] = $result;

        return $result;
    }

    /**
     * Aggregate material quantities by type_id.
     */
    public function aggregateMaterials(array $materials): array
    {
        $aggregated = [];
        foreach ($materials as $material) {
            $typeId = $material['type_id'];
            if (isset($aggregated[$typeId])) {
                $aggregated[$typeId]['quantity'] += $material['quantity'];
            } else {
                $aggregated[$typeId] = [
                    'type_id' => $typeId,
                    'name' => $material['name'],
                    'quantity' => $material['quantity'],
                ];
            }
        }

        return array_values($aggregated);
    }

    /**
     * Add a material to the raw materials array, aggregating by type_id.
     */
    protected function addToRawMaterials(array &$materials, array $material): void
    {
        $typeId = $material['type_id'];
        foreach ($materials as &$existing) {
            if ($existing['type_id'] === $typeId) {
                $existing['quantity'] += $material['quantity'];

                return;
            }
        }
        $materials[] = $material;
    }

    /**
     * Add slots to the intermediate slots array, aggregating by product_type_id.
     */
    protected function addToSlots(array &$slots, array $slot): void
    {
        $typeId = $slot['product_type_id'];
        foreach ($slots as &$existing) {
            if ($existing['product_type_id'] === $typeId) {
                $existing['slots'] += $slot['slots'];
                $existing['total_runs'] += $slot['total_runs'];

                return;
            }
        }
        $slots[] = $slot;
    }
}
