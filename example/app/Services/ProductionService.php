<?php

namespace App\Services;

use App\Models\BlueprintActivity;
use App\Models\Character;
use App\Models\EveType;
use App\Services\Esi\EsiRequestException;
use Illuminate\Support\Facades\Log;

class ProductionService
{
    public function __construct(protected EsiService $esi)
    {
    }

    /**
     * Build the can-produce list for all characters belonging to a user.
     *
     * Returns an array of sources, each with available materials and buildable items.
     */
    public function getCanProduceForUser(int $userId): array
    {
        $characters = Character::where('user_id', $userId)->get();
        $sources = [];

        foreach ($characters as $character) {
            if ($character->is_esi_active === false) {
                continue;
            }

            try {
                // Character personal source
                $characterSource = $this->buildCharacterSource($character);

                // Corporation source (if character has Director role)
                $corpSource = $this->buildCorporationSource($character);
            } catch (EsiRequestException $e) {
                // A token revoked or character unlinked mid-request has no
                // usable data; other ESI failures still propagate.
                if ($e->outcome === 'credential') {
                    continue;
                }
                throw $e;
            }

            $sources[] = $characterSource;
            if ($corpSource) {
                $sources[] = $corpSource;
            }
        }

        return $sources;
    }

    /**
     * Build production data for a single character's personal assets.
     */
    protected function buildCharacterSource(Character $character): array
    {
        $blueprints = $this->esi->getCharacterBlueprints($character);
        $assets = $this->esi->getCharacterAssets($character);

        $materials = $this->aggregateMaterials($assets);
        $buildable = $this->calculateBuildableItems($blueprints, $materials);

        return [
            'type' => 'character',
            'character_id' => $character->character_id,
            'character_name' => $character->name,
            'portrait_url' => $character->portrait_url,
            'corporation_name' => $character->corporation_name,
            'material_count' => count($materials),
            'blueprint_count' => count($blueprints),
            'buildable' => $buildable,
            'available_materials' => $this->resolveAvailableMaterials($materials),
        ];
    }

    /**
     * Build production data from a corporation's hangars (if the character has Director role).
     */
    protected function buildCorporationSource(Character $character): ?array
    {
        $scopes = $character->scopes ?? [];
        $hasCorpBlueprints = in_array('esi-corporations.read_blueprints.v1', $scopes);
        $hasCorpAssets = in_array('esi-assets.read_corporation_assets.v1', $scopes);
        $hasRoles = in_array('esi-characters.read_corporation_roles.v1', $scopes);

        if (!$hasCorpBlueprints || !$hasCorpAssets || !$hasRoles) {
            return null;
        }

        $roles = $this->esi->getCharacterRoles($character);
        $characterRoles = $roles['roles'] ?? [];

        if (!in_array('Director', $characterRoles) && !in_array('Station_Manager', $characterRoles)) {
            return null;
        }

        $corporationId = $character->corporation_id;
        if (!$corporationId) {
            return null;
        }

        $blueprints = $this->esi->getCorporationBlueprints($character, $corporationId);
        $assets = $this->esi->getCorporationAssets($character, $corporationId);

        $materials = $this->aggregateMaterials($assets);
        $buildable = $this->calculateBuildableItems($blueprints, $materials);

        return [
            'type' => 'corporation',
            'character_id' => $character->character_id,
            'character_name' => $character->name,
            'corporation_id' => $corporationId,
            'corporation_name' => $character->corporation_name,
            'corporation_logo' => $character->corporation_logo,
            'material_count' => count($materials),
            'blueprint_count' => count($blueprints),
            'buildable' => $buildable,
            'available_materials' => $this->resolveAvailableMaterials($materials),
        ];
    }

    /**
     * Aggregate asset quantities by type_id.
     *
     * @param  array  $assets  Raw ESI asset entries
     * @return array  [type_id => total_quantity]
     */
    protected function aggregateMaterials(array $assets): array
    {
        $materials = [];

        foreach ($assets as $asset) {
            $typeId = $asset['type_id'] ?? 0;
            $quantity = $asset['quantity'] ?? 0;
            if ($typeId && $quantity > 0) {
                $materials[$typeId] = ($materials[$typeId] ?? 0) + $quantity;
            }
        }

        return $materials;
    }

    /**
     * Resolve aggregated materials into named entries sorted by name.
     *
     * @param  array  $materials  [type_id => total_quantity]
     * @return array  List of ['type_id' => int, 'name' => string, 'quantity' => int]
     */
    protected function resolveAvailableMaterials(array $materials): array
    {
        if (empty($materials)) {
            return [];
        }

        $typeIds = array_keys($materials);
        $types = EveType::whereIn('type_id', $typeIds)->pluck('name', 'type_id');

        $resolved = [];
        foreach ($materials as $typeId => $quantity) {
            $resolved[] = [
                'type_id' => $typeId,
                'name' => $types[$typeId] ?? "Type #{$typeId}",
                'quantity' => $quantity,
            ];
        }

        usort($resolved, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $resolved;
    }

    /**
     * Cross-reference blueprints with available materials and return buildable items
     * with the maximum number of runs possible.
     */
    protected function calculateBuildableItems(array $blueprints, array $materials): array
    {
        $buildable = [];

        foreach ($blueprints as $bp) {
            $bpTypeId = $bp['type_id'] ?? 0;
            $me = $bp['material_efficiency'] ?? 0;
            $te = $bp['time_efficiency'] ?? 0;
            $maxRuns = $bp['runs'] ?? -1; // -1 = BPO (unlimited)

            if (!$bpTypeId) {
                continue;
            }

            // Look up manufacturing activity for this blueprint
            $activity = BlueprintActivity::where('blueprint_type_id', $bpTypeId)
                ->where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
                ->first();

            if (!$activity || empty($activity->materials) || empty($activity->products)) {
                continue;
            }

            $requiredMaterials = $activity->materials;
            $products = $activity->products;
            $productTypeId = $products[0]['type_id'] ?? 0;
            $productName = $products[0]['name'] ?? null;
            $productQtyPerRun = (int) ($products[0]['quantity'] ?? 1);

            if (!$productTypeId) {
                continue;
            }

            if (!$productName) {
                $type = EveType::find($productTypeId);
                $productName = $type->name ?? "Type #{$productTypeId}";
            }

            // Calculate max runs possible based on available materials
            $maxPossibleRuns = PHP_INT_MAX;

            $materialDetails = [];
            foreach ($requiredMaterials as $mat) {
                $matTypeId = $mat['type_id'] ?? 0;
                $baseQty = (int) ($mat['quantity'] ?? 0);
                if (!$matTypeId || !$baseQty) {
                    continue;
                }

                // Apply ME reduction: ME is a percentage reduction on base quantity.
                // max(1, ...) ensures at least 1 unit is required (EVE rounds up, never zero).
                $adjustedQty = max(1, (int) ceil(round($baseQty * (1 - $me / 100), 2)));
                $available = $materials[$matTypeId] ?? 0;
                $possibleRuns = $adjustedQty > 0 ? (int) floor($available / $adjustedQty) : 0;
                $maxPossibleRuns = min($maxPossibleRuns, $possibleRuns);

                $matName = $mat['name'] ?? null;
                if (!$matName) {
                    $type = EveType::find($matTypeId);
                    $matName = $type->name ?? "Type #{$matTypeId}";
                }

                $materialDetails[] = [
                    'type_id' => $matTypeId,
                    'name' => $matName,
                    'required_per_run' => $adjustedQty,
                    'available' => $available,
                    'runs_possible' => $possibleRuns,
                ];
            }

            if ($maxPossibleRuns === PHP_INT_MAX) {
                $maxPossibleRuns = 0;
            }

            // Cap by BPC run limit
            if ($maxRuns > 0) {
                $maxPossibleRuns = min($maxPossibleRuns, $maxRuns);
            }

            if ($maxPossibleRuns <= 0) {
                continue;
            }

            $totalOutput = $maxPossibleRuns * $productQtyPerRun;

            $buildable[] = [
                'blueprint_type_id' => $bpTypeId,
                'product_type_id' => $productTypeId,
                'product_name' => $productName,
                'product_qty_per_run' => $productQtyPerRun,
                'me' => $me,
                'te' => $te,
                'max_runs' => $maxRuns,
                'buildable_runs' => $maxPossibleRuns,
                'total_output' => $totalOutput,
                'materials' => $materialDetails,
            ];
        }

        // Group items with the same product and ME level
        $grouped = [];
        foreach ($buildable as $item) {
            $key = $item['product_type_id'] . '_' . $item['me'];
            if (isset($grouped[$key])) {
                $grouped[$key]['buildable_runs'] += $item['buildable_runs'];
                $grouped[$key]['total_output'] += $item['total_output'];
                // If any copy is a BPO (-1), the group is effectively unlimited
                if ($item['max_runs'] === -1 || $grouped[$key]['max_runs'] === -1) {
                    $grouped[$key]['max_runs'] = -1;
                } else {
                    $grouped[$key]['max_runs'] += $item['max_runs'];
                }
            } else {
                $grouped[$key] = $item;
            }
        }
        $buildable = array_values($grouped);

        // Sort by buildable runs descending
        usort($buildable, fn($a, $b) => $b['buildable_runs'] <=> $a['buildable_runs']);

        return $buildable;
    }
}
