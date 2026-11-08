<?php

namespace App\Services\Esi;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Models\TypeMaterial;
use App\Services\EsiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fuzzwork SDE helpers: blueprint activities and type materials.
 */
class SdeClient
{
    public function __construct(
        protected UniverseClient $universe,
    ) {}

    /**
     * Fetch blueprint/schematic SDE data from the Fuzzwork SDE API.
     *
     * ESI does not expose manufacturing activities (materials, products, time) for
     * blueprints or reaction formulas. This data lives in the EVE Static Data Export
     * (SDE). The Fuzzwork API mirrors it in a convenient JSON format.
     *
     * Returns the full blueprint record keyed by typeID, e.g.:
     *   { "activities": { "1": { "materials": [...], "products": [...], "time": N }, ... } }
     *
     * Activity IDs: 1 = Manufacturing, 5 = Copying, 8 = Invention, 9 = Reaction
     */
    public function getBlueprintSdeData(int $typeId): ?array
    {
        $cached = Cache::get("sde.blueprint.{$typeId}");
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::timeout(15)->get(
                'https://www.fuzzwork.co.uk/blueprint/api/blueprint.php',
                ['typeid' => $typeId]
            );

            if (! $response->successful()) {
                Log::warning("Fuzzwork SDE: HTTP {$response->status()} for blueprint {$typeId}");

                return null;
            }

            $data = $response->json();
            if (empty($data)) {
                Log::warning("Fuzzwork SDE: empty response for blueprint {$typeId}");

                return null;
            }

            // The API returns a flat object: {requestedid, blueprintSkills, blueprintDetails, activityMaterials, decryptors}
            // NOT wrapped by type ID.
            $result = $data;

            // Only cache successful non-empty responses (30 days)
            if (! empty($result) && ! empty($result['activityMaterials'])) {
                Cache::put("sde.blueprint.{$typeId}", $result, EsiService::SDE_CACHE_TTL);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error("Fuzzwork SDE error fetching blueprint {$typeId}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Resolve SDE blueprint data into a normalised structure with material names.
     *
     * Checks the local blueprint_activities table first, then falls back to the
     * Fuzzwork SDE API if no local record exists.
     *
     * @param  int  $blueprintTypeId  The blueprint/formula type ID
     * @param  int  $activityId  Activity to resolve (1=manufacturing, 5=copying, 8=invention, 11=reaction)
     * @return array|null { materials: [...], products: [...], time: int } or null
     */
    public function resolveBlueprintActivity(int $blueprintTypeId, int $activityId = 1): ?array
    {
        // 1. Try the local database first
        $local = BlueprintActivity::where('blueprint_type_id', $blueprintTypeId)
            ->where('activity_id', $activityId)
            ->first();

        if ($local) {
            return [
                'materials' => $this->dropDuplicateMaterials($local->materials ?? []),
                'products' => $local->products ?? [],
                'time' => $local->time,
            ];
        }

        Log::info("No local blueprint data for type {$blueprintTypeId}, activity {$activityId} — trying Fuzzwork API");

        // 2. Fall back to Fuzzwork SDE API
        $sde = $this->getBlueprintSdeData($blueprintTypeId);
        if (! $sde || empty($sde['activityMaterials'])) {
            Log::warning("No SDE data available for blueprint {$blueprintTypeId}. Run 'php artisan sde:import-blueprints' to populate local data.");

            return null;
        }

        // Materials are in activityMaterials keyed by activity ID
        $activityMats = $sde['activityMaterials'][(string) $activityId] ?? $sde['activityMaterials'][$activityId] ?? null;
        if (! $activityMats) {
            return null;
        }

        // Fuzzwork tags each entry with `maketype`, the type ID of the blueprint
        // that produces it. For reactions the output is listed inside
        // activityMaterials with maketype pointing back at this blueprint, so
        // split those entries out as products rather than input materials.
        $productItems = [];
        $materialItems = [];
        foreach ($activityMats as $item) {
            $makeType = (int) ($item['maketype'] ?? -1);
            if ($blueprintTypeId > 0 && $makeType === $blueprintTypeId) {
                $productItems[] = $item;
            } else {
                $materialItems[] = $item;
            }
        }

        // Resolve material names — Fuzzwork returns [{typeid, name, quantity, maketype}]
        $materials = $this->resolveSdeTypeList($materialItems);

        // Products: prefer entries marked as produced by this blueprint, and
        // fall back to blueprintDetails.productTypeID only when none are present.
        $details = $sde['blueprintDetails'] ?? [];
        $products = [];
        if (! empty($productItems)) {
            $products = $this->resolveSdeTypeList($productItems, 1);
        } else {
            $productTypeId = (int) ($details['productTypeID'] ?? 0);
            if ($productTypeId) {
                $productName = $details['productTypeName'] ?? null;
                if (! $productName) {
                    $localType = EveType::find($productTypeId);
                    $productName = $localType?->name;
                    if (! $productName) {
                        $typeInfo = $this->universe->getTypeInfo($productTypeId);
                        $productName = $typeInfo['name'] ?? 'Unknown';
                    }
                }
                $products[] = [
                    'type_id' => $productTypeId,
                    'name' => $productName,
                    'quantity' => (int) ($details['productQuantity'] ?? 1),
                ];
            }
        }

        // Time is in blueprintDetails.times keyed by activity ID
        $times = $details['times'] ?? [];
        $time = (int) ($times[(string) $activityId] ?? $times[$activityId] ?? 0);

        return [
            'materials' => $this->dropDuplicateMaterials($materials),
            'products' => $products,
            'time' => $time,
        ];
    }

    /**
     * Remove duplicate materials that share the same type_id, keeping only the
     * first occurrence. Blueprint material lists should contain each material
     * only once; duplicate rows (from imports or SDE sources) would otherwise
     * show up twice in the manufacturing calculator output.
     *
     * @param  array  $materials  List of [type_id, name, quantity] entries.
     * @return array Deduplicated list preserving first-seen order.
     */
    protected function dropDuplicateMaterials(array $materials): array
    {
        $merged = [];

        foreach ($materials as $material) {
            $typeId = (int) ($material['type_id'] ?? $material['typeid'] ?? $material['typeID'] ?? 0);
            if (! $typeId) {
                continue;
            }

            if (isset($merged[$typeId])) {
                continue;
            }

            $merged[$typeId] = [
                'type_id' => $typeId,
                'name' => $material['name'] ?? 'Unknown',
                'quantity' => (int) ($material['quantity'] ?? 0),
            ];
        }

        return array_values($merged);
    }

    /**
     * Resolve a materials list from Fuzzwork SDE format.
     *
     * Fuzzwork returns materials as arrays of {typeid, name, quantity, maketype} objects.
     */
    protected function resolveSdeTypeList(array $items, int $defaultQty = 0): array
    {
        $resolved = [];

        // Pre-load names for any items missing a name in a single query to avoid
        // an N+1 EveType::find() lookup per item.
        $missingNameTypeIds = [];
        foreach ($items as $info) {
            $typeId = (int) ($info['typeid'] ?? $info['typeID'] ?? $info['type_id'] ?? 0);
            if ($typeId && empty($info['name'])) {
                $missingNameTypeIds[] = $typeId;
            }
        }

        $nameMap = [];
        if (! empty($missingNameTypeIds)) {
            $nameMap = EveType::whereIn('type_id', array_values(array_unique($missingNameTypeIds)))
                ->pluck('name', 'type_id')
                ->toArray();
        }

        foreach ($items as $info) {
            // Fuzzwork format: {typeid, name, quantity, maketype}
            $typeId = (int) ($info['typeid'] ?? $info['typeID'] ?? $info['type_id'] ?? 0);
            if (! $typeId) {
                continue;
            }

            $quantity = (int) ($info['quantity'] ?? $defaultQty);
            $name = $info['name'] ?? null;

            if (! $name) {
                $name = $nameMap[$typeId] ?? null;
                if (! $name) {
                    $typeInfo = $this->universe->getTypeInfo($typeId);
                    $name = $typeInfo['name'] ?? 'Unknown';
                }
            }

            $resolved[] = [
                'type_id' => $typeId,
                'name' => $name,
                'quantity' => $quantity,
            ];
        }

        return $resolved;
    }

    /**
     * Get reprocessing materials for a type.
     *
     * Checks the local type_materials table first (populated by sde:import),
     * then falls back to the Fuzzwork SDE API if not found locally.
     *
     * Returns an array of materials that the type yields when reprocessed at 100% yield,
     * e.g. [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100], ...]
     *
     * @return array|null Materials array or null if the type has no reprocessing output.
     */
    public function getTypeMaterials(int $typeId): ?array
    {
        // 1. Check local SDE data first (populated by sde:import)
        try {
            $localRows = TypeMaterial::where('type_id', $typeId)->get();
            if ($localRows->isNotEmpty()) {
                $matTypeIds = $localRows->pluck('material_type_id')->unique()->values()->all();
                $nameMap = EveType::whereIn('type_id', $matTypeIds)->pluck('name', 'type_id')->all();

                $materials = [];
                foreach ($localRows as $row) {
                    $materials[] = [
                        'type_id' => $row->material_type_id,
                        'name' => $nameMap[$row->material_type_id] ?? 'Unknown',
                        'quantity' => $row->quantity,
                    ];
                }

                return $materials;
            }
        } catch (\Exception $e) {
            // Table may not exist yet; fall through to API
        }

        // 2. Fall back to Fuzzwork SDE API
        $cacheKey = "sde.typematerials.{$typeId}";

        return Cache::remember($cacheKey, EsiService::SDE_CACHE_TTL, function () use ($typeId) {
            try {
                $response = Http::timeout(5)->get(
                    'https://www.fuzzwork.co.uk/sde/api/invTypeMaterials/',
                    ['typeID' => $typeId]
                );

                if (! $response->successful()) {
                    Log::warning("Fuzzwork SDE: HTTP {$response->status()} for typeMaterials {$typeId}");

                    return null;
                }

                $data = $response->json();
                if (empty($data)) {
                    return null;
                }

                $materials = [];
                $persistRows = [];
                foreach ($data as $row) {
                    $matTypeId = (int) ($row['materialTypeID'] ?? $row['typeid'] ?? 0);
                    $quantity = (int) ($row['quantity'] ?? 0);
                    if (! $matTypeId || $quantity <= 0) {
                        continue;
                    }

                    $localType = EveType::find($matTypeId);
                    $name = $localType?->name ?? 'Unknown';

                    $materials[] = [
                        'type_id' => $matTypeId,
                        'name' => $name,
                        'quantity' => $quantity,
                    ];

                    $persistRows[] = [
                        'type_id' => $typeId,
                        'material_type_id' => $matTypeId,
                        'quantity' => $quantity,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // Persist to local DB for future lookups
                if (! empty($persistRows)) {
                    try {
                        TypeMaterial::upsert($persistRows, ['type_id', 'material_type_id'], ['quantity', 'updated_at']);
                    } catch (\Exception $e) {
                        // Non-critical — log and continue
                        Log::warning("Failed to persist typeMaterials for type {$typeId}: ".$e->getMessage());
                    }
                }

                return ! empty($materials) ? $materials : null;
            } catch (\Exception $e) {
                Log::error("Fuzzwork SDE error fetching typeMaterials {$typeId}: ".$e->getMessage());

                return null;
            }
        });
    }

    /**
     * Batch-load reprocessing materials for multiple type IDs.
     *
     * Prefers the local type_materials DB table. For any type IDs not found
     * locally, falls back to the Fuzzwork SDE API and persists the results.
     * To prevent timeouts, API lookups are capped at a maximum number per call.
     *
     * @param  array<int>  $typeIds
     * @param  int  $apiLimit  Maximum number of API lookups per invocation
     * @return array<int, array|null> Keyed by type_id → materials array (or null)
     */
    public function getTypeMaterialsBatch(array $typeIds, int $apiLimit = 5): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));

        // 1. Load from DB in a single query
        $dbRows = TypeMaterial::whereIn('type_id', $typeIds)->get();
        $dbGrouped = [];
        foreach ($dbRows as $row) {
            $dbGrouped[$row->type_id][] = $row;
        }

        // Pre-load all material type names needed
        $allMatTypeIds = $dbRows->pluck('material_type_id')->unique()->values()->all();
        $nameMap = [];
        if (! empty($allMatTypeIds)) {
            $nameMap = EveType::whereIn('type_id', $allMatTypeIds)
                ->pluck('name', 'type_id')
                ->all();
        }

        $result = [];
        $missing = [];

        foreach ($typeIds as $typeId) {
            if (isset($dbGrouped[$typeId])) {
                $materials = [];
                foreach ($dbGrouped[$typeId] as $row) {
                    $materials[] = [
                        'type_id' => $row->material_type_id,
                        'name' => $nameMap[$row->material_type_id] ?? 'Unknown',
                        'quantity' => $row->quantity,
                    ];
                }
                $result[$typeId] = $materials;
            } else {
                $missing[] = $typeId;
            }
        }

        // 2. Fetch missing from API and persist (capped to prevent timeouts)
        $fetched = 0;
        $totalMissing = count($missing);
        foreach ($missing as $typeId) {
            if ($fetched >= $apiLimit) {
                Log::info("getTypeMaterialsBatch: API limit ({$apiLimit}) reached, skipping remaining ".($totalMissing - $fetched).' types');
                break;
            }

            $materials = $this->getTypeMaterials($typeId);
            $result[$typeId] = $materials;
            $fetched++;

            // Persist to DB for future use
            if ($materials) {
                $rows = array_map(fn ($mat) => [
                    'type_id' => $typeId,
                    'material_type_id' => $mat['type_id'],
                    'quantity' => $mat['quantity'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $materials);

                TypeMaterial::upsert($rows, ['type_id', 'material_type_id'], ['quantity', 'updated_at']);
            }
        }

        return $result;
    }
}
