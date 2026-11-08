<?php

namespace App\Services;

use App\Models\Character;
use App\Models\EveType;
use Illuminate\Support\Facades\Cache;

class ReprocessingService
{
    // Reprocessing skill type IDs
    public const SKILL_REPROCESSING = 3385;

    public const SKILL_REPROCESSING_EFFICIENCY = 3389;

    public const SKILL_SCRAPMETAL_PROCESSING = 12196;

    // NPC station base reprocessing yield (50%)
    public const BASE_YIELD = 0.50;

    /** Cache TTL for per-character reprocessing analysis: 10 minutes. */
    protected const ANALYSIS_CACHE_TTL = 600;

    public function __construct(
        protected EsiService $esi,
        protected MarketService $marketService
    ) {}

    /**
     * Get reprocessing opportunities for all characters belonging to a user.
     *
     * Returns an array of character data, each containing locations with items
     * that are more valuable when reprocessed than sold on the market.
     */
    public function getReprocessingOpportunities(int $userId): array
    {
        $characters = Character::where('user_id', $userId)->get();

        // Collect characters that can resolve player-owned structure names.
        // Only characters with the structure-read scope can call that endpoint.
        $structureCharacters = $characters->filter(
            fn ($c) => $c->hasScope('esi-universe.read_structures.v1')
        )->values()->all();

        $result = [];

        foreach ($characters as $character) {
            $charData = $this->analyzeCharacterAssets($character, $structureCharacters);
            $result[] = $charData;
        }

        return $result;
    }

    /**
     * Analyze a character's assets to find items worth more when reprocessed.
     *
     * @param  array  $structureCharacters  Characters with esi-universe.read_structures.v1 scope
     */
    protected function analyzeCharacterAssets(Character $character, array $structureCharacters = []): array
    {
        $cacheKey = "reprocessing.analysis.{$character->character_id}";

        return Cache::remember($cacheKey, self::ANALYSIS_CACHE_TTL, function () use ($character, $structureCharacters) {
            $skills = $this->getReprocessingSkills($character);
            $yield = $this->calculateYield($skills);
            $assets = $this->esi->getCharacterAssets($character);

            // Group assets by location_id
            $locationGroups = [];
            $allTypeIds = [];
            foreach ($assets as $asset) {
                $locationId = $asset['location_id'] ?? 0;
                if (! $locationId) {
                    continue;
                }
                $locationGroups[$locationId][] = $asset;

                // Collect all type IDs upfront for a single batch lookup
                $typeId = $asset['type_id'] ?? 0;
                if ($typeId && empty($asset['is_singleton'])) {
                    $allTypeIds[$typeId] = true;
                }
            }

            // Single batch lookup for ALL type materials across all locations
            $allReprocessData = ! empty($allTypeIds)
                ? $this->esi->getTypeMaterialsBatch(array_keys($allTypeIds))
                : [];

            $locations = [];
            foreach ($locationGroups as $locationId => $items) {
                $profitableItems = $this->findProfitableReprocessing($items, $yield, $allReprocessData);
                if (! empty($profitableItems)) {
                    $locationName = $this->resolveLocationName((int) $locationId, $structureCharacters);
                    $totalReprocessValue = array_sum(array_column($profitableItems, 'reprocess_value'));
                    $totalMarketValue = array_sum(array_column($profitableItems, 'market_value'));

                    $locations[] = [
                        'location_id' => $locationId,
                        'location_name' => $locationName,
                        'items' => $profitableItems,
                        'total_reprocess_value' => $totalReprocessValue,
                        'total_market_value' => $totalMarketValue,
                        'total_profit' => $totalReprocessValue - $totalMarketValue,
                    ];
                }
            }

            // Sort locations by total profit descending
            usort($locations, fn ($a, $b) => $b['total_profit'] <=> $a['total_profit']);

            return [
                'character_id' => $character->character_id,
                'character_name' => $character->name,
                'portrait_url' => $character->portrait_url,
                'corporation_name' => $character->corporation_name,
                'yield_pct' => round($yield * 100, 1),
                'skills' => $skills,
                'locations' => $locations,
                'total_profit' => array_sum(array_column($locations, 'total_profit')),
            ];
        });
    }

    /**
     * Get reprocessing-related skill levels for a character.
     */
    public function getReprocessingSkills(Character $character): array
    {
        $skillsData = $this->esi->getCharacterSkills($character);
        $skills = [];

        if ($skillsData && isset($skillsData['skills'])) {
            foreach ($skillsData['skills'] as $skill) {
                $skills[$skill['skill_id']] = $skill['active_skill_level'] ?? $skill['trained_skill_level'] ?? 0;
            }
        }

        return [
            'reprocessing' => $skills[self::SKILL_REPROCESSING] ?? 0,
            'reprocessing_efficiency' => $skills[self::SKILL_REPROCESSING_EFFICIENCY] ?? 0,
            'scrapmetal_processing' => $skills[self::SKILL_SCRAPMETAL_PROCESSING] ?? 0,
        ];
    }

    /**
     * Calculate the reprocessing yield based on skills.
     *
     * Formula: base_yield × (1 + reprocessing × 0.03) × (1 + efficiency × 0.02) × (1 + scrapmetal × 0.02)
     */
    public function calculateYield(array $skills): float
    {
        return self::BASE_YIELD
            * (1 + ($skills['reprocessing'] ?? 0) * 0.03)
            * (1 + ($skills['reprocessing_efficiency'] ?? 0) * 0.02)
            * (1 + ($skills['scrapmetal_processing'] ?? 0) * 0.02);
    }

    /**
     * Find items in an asset list that are more valuable when reprocessed.
     *
     * @param  array  $items  Raw ESI asset entries
     * @param  float  $yield  Reprocessing yield (0-1)
     * @param  array  $allReprocessData  Pre-loaded type materials keyed by type_id
     * @return array Items where reprocess value > market value
     */
    protected function findProfitableReprocessing(array $items, float $yield, array $allReprocessData): array
    {
        // Aggregate quantities per type (skip assembled containers, ships, etc.)
        $typeQuantities = [];
        foreach ($items as $asset) {
            $typeId = $asset['type_id'] ?? 0;
            if (! $typeId) {
                continue;
            }
            // Skip assembled items (singleton) — they cannot be reprocessed directly
            if (! empty($asset['is_singleton'])) {
                continue;
            }
            $qty = $asset['quantity'] ?? 1;
            $typeQuantities[$typeId] = ($typeQuantities[$typeId] ?? 0) + $qty;
        }

        if (empty($typeQuantities)) {
            return [];
        }

        $allTypeIds = array_keys($typeQuantities);

        // Use pre-loaded reprocessing data, filtering to types in this location
        $typeReprocessData = [];
        foreach ($allTypeIds as $typeId) {
            if (! empty($allReprocessData[$typeId])) {
                $typeReprocessData[$typeId] = $allReprocessData[$typeId];
            }
        }

        // Collect all material type IDs we'll need prices for
        $materialTypeIds = [];
        foreach ($typeReprocessData as $materials) {
            foreach ($materials as $mat) {
                $materialTypeIds[$mat['type_id']] = true;
            }
        }

        // Get all needed prices in one batch
        $priceTypeIds = array_unique(array_merge($allTypeIds, array_keys($materialTypeIds)));
        $prices = $this->marketService->getPrices($priceTypeIds);

        // Batch-load type names and portion sizes from the DB
        $eveTypes = EveType::whereIn('type_id', array_keys($typeReprocessData))
            ->get(['type_id', 'name', 'portion_size'])
            ->keyBy('type_id');

        $typeNames = $eveTypes->pluck('name', 'type_id')->all();
        $portionSizes = $eveTypes->pluck('portion_size', 'type_id')->all();

        $profitable = [];
        foreach ($typeReprocessData as $typeId => $materials) {
            $quantity = $typeQuantities[$typeId];

            // Determine how many reprocessing batches we can run
            $portionSize = max($portionSizes[$typeId] ?? 1, 1);
            $batches = (int) floor($quantity / $portionSize);
            if ($batches < 1) {
                continue;
            }

            // Get item market value. Skip items with no known price: treating
            // them as worthless would count the whole reprocess value as profit.
            $itemUnitPrice = $this->unitPrice($prices[$typeId] ?? null);
            if ($itemUnitPrice <= 0) {
                continue;
            }
            $marketValue = $itemUnitPrice * $quantity;

            // Calculate reprocessing value
            $reprocessValue = 0;
            $materialDetails = [];
            foreach ($materials as $mat) {
                $matQty = (int) floor($mat['quantity'] * $yield) * $batches;
                $matUnitPrice = $this->unitPrice($prices[$mat['type_id']] ?? null);
                $matValue = $matUnitPrice * $matQty;
                $reprocessValue += $matValue;

                $materialDetails[] = [
                    'type_id' => $mat['type_id'],
                    'name' => $mat['name'],
                    'quantity' => $matQty,
                    'unit_price' => $matUnitPrice,
                    'total_value' => $matValue,
                ];
            }

            $profit = $reprocessValue - $marketValue;

            // Only include items that are more valuable reprocessed
            if ($profit > 0 && $reprocessValue > 0) {
                $typeName = $typeNames[$typeId] ?? null;
                if (! $typeName) {
                    $typeInfo = $this->esi->getTypeInfo($typeId);
                    $typeName = $typeInfo['name'] ?? 'Unknown';
                }

                $profitable[] = [
                    'type_id' => $typeId,
                    'name' => $typeName,
                    'quantity' => $quantity,
                    'market_value' => $marketValue,
                    'reprocess_value' => $reprocessValue,
                    'profit' => $profit,
                    'profit_pct' => $marketValue > 0 ? round(($profit / $marketValue) * 100, 1) : 0,
                    'unit_market_price' => $itemUnitPrice,
                    'materials' => $materialDetails,
                    'icon_url' => EsiService::getTypeImageUrl($typeId, 'icon', 32),
                ];
            }
        }

        // Sort by profit descending
        usort($profitable, fn ($a, $b) => $b['profit'] <=> $a['profit']);

        return $profitable;
    }

    /**
     * Unit price from a market price row: lowest sell, else average, else 0.
     * A zero sell_min means no sell orders, so it falls through to the average.
     */
    private function unitPrice(?object $price): float
    {
        if (! $price) {
            return 0.0;
        }

        return (float) (($price->sell_min ?? 0) ?: ($price->average_price ?? 0));
    }

    /**
     * Resolve a location ID to a human-readable name.
     *
     * @param  array  $structureCharacters  Characters with esi-universe.read_structures.v1 scope
     */
    protected function resolveLocationName(int $locationId, array $structureCharacters = []): string
    {
        // NPC stations have IDs between 60000000 and 64000000
        if ($locationId >= 60000000 && $locationId < 64000000) {
            $station = $this->esi->getStation($locationId);
            if ($station && isset($station['name'])) {
                return $station['name'];
            }
        }

        // Player structures have IDs above 1,000,000,000,000
        if ($locationId > AssetService::PLAYER_STRUCTURE_ID_THRESHOLD) {
            // Skip structures we've recently failed to resolve (avoid hammering ESI)
            $failCacheKey = "esi.structure.fail.{$locationId}";
            if (! Cache::has($failCacheKey)) {
                foreach ($structureCharacters as $structChar) {
                    $structure = $this->esi->getStructure($locationId, $structChar);
                    if ($structure && isset($structure['name'])) {
                        $name = $structure['name'];

                        // Append the solar system name if available
                        if (isset($structure['solar_system_id'])) {
                            $system = $this->esi->getSystem($structure['solar_system_id']);
                            if ($system && isset($system['name'])) {
                                $name .= " ({$system['name']})";
                            }
                        }

                        return $name;
                    }
                }

                // Fallback: try the public POST /universe/names/ endpoint
                $names = $this->esi->resolveUniverseNames([$locationId]);
                foreach ($names as $entry) {
                    if (isset($entry['id'], $entry['name']) && (int) $entry['id'] === $locationId) {
                        return $entry['name'];
                    }
                }

                // Cache the failure for 10 minutes to avoid repeated API calls
                Cache::put($failCacheKey, true, AssetService::STRUCTURE_FAIL_CACHE_TTL);
            }

            return "Structure #{$locationId}";
        }

        return "Location #{$locationId}";
    }
}
