<?php

namespace App\Jobs;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Services\EsiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessBlueprintDataChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600;

    /**
     * @param  array<int>  $typeIds  Chunk of blueprint/reaction type IDs to process
     */
    public function __construct(
        protected array $typeIds,
    ) {
        $this->onQueue('updates');
    }

    public function handle(EsiService $esi): void
    {
        $imported = 0;
        $failed = 0;

        // Phase 1: Fetch all SDE data for this chunk, collecting referenced type IDs
        $sdeResults = [];
        $referencedIds = [];

        foreach ($this->typeIds as $typeId) {
            $sdeData = $this->fetchFromFuzzwork($typeId);
            usleep(100000); // 100ms delay to respect Fuzzwork API rate limits

            // Fuzzwork returns: {activityMaterials: {actId: [{typeid,name,quantity}...]}, blueprintDetails: {times: {actId: seconds}, productTypeID, productTypeName, productQuantity}}
            if (!$sdeData || empty($sdeData['activityMaterials'])) {
                $failed++;
                continue;
            }

            $sdeResults[$typeId] = $sdeData;

            // Collect type IDs for materials that may need name resolution
            foreach ($sdeData['activityMaterials'] as $activityMats) {
                foreach ($activityMats as $mat) {
                    if (empty($mat['name'])) {
                        $referencedIds[] = (int) ($mat['typeid'] ?? $mat['typeID'] ?? $mat['type_id'] ?? 0);
                    }
                }
            }

            // Collect product type ID
            $productTypeId = (int) ($sdeData['blueprintDetails']['productTypeID'] ?? 0);
            if ($productTypeId && empty($sdeData['blueprintDetails']['productTypeName'])) {
                $referencedIds[] = $productTypeId;
            }
        }

        // Phase 2: Bulk-fetch all referenced type names from local DB
        $localNames = [];
        if (!empty($referencedIds)) {
            $localNames = EveType::whereIn('type_id', array_unique($referencedIds))
                ->pluck('name', 'type_id')
                ->toArray();
        }

        // Phase 3: Build upsert batch using cached SDE data and pre-fetched names
        $batch = [];

        foreach ($sdeResults as $typeId => $sdeData) {
            $details = $sdeData['blueprintDetails'] ?? [];
            $times = $details['times'] ?? [];

            // Build the fallback product entry from blueprintDetails. This is
            // used for activities (e.g. manufacturing) where the output is not
            // listed inside activityMaterials.
            $productTypeId = (int) ($details['productTypeID'] ?? 0);
            $productName = $details['productTypeName'] ?? null;
            if (!$productName && $productTypeId) {
                $productName = $localNames[$productTypeId] ?? null;
                if (!$productName) {
                    $typeInfo = $esi->getTypeInfo($productTypeId);
                    $productName = $typeInfo['name'] ?? 'Unknown';
                }
            }
            $productQuantity = (int) ($details['productQuantity'] ?? 1);

            $fallbackProducts = [];
            if ($productTypeId) {
                $fallbackProducts[] = [
                    'type_id' => $productTypeId,
                    'name' => $productName ?? 'Unknown',
                    'quantity' => $productQuantity,
                ];
            }

            foreach ($sdeData['activityMaterials'] as $activityId => $activityMats) {
                // Fuzzwork tags each entry with `maketype`, the type ID of the
                // blueprint that produces it. For reactions the output is listed
                // inside activityMaterials with maketype pointing back at this
                // blueprint, so split those entries out as products.
                [$productItems, $materialItems] = $this->partitionByMakeType($activityMats, $typeId);

                $materials = $this->resolveTypeList($materialItems, $localNames, $esi);
                $products = !empty($productItems)
                    ? $this->resolveTypeList($productItems, $localNames, $esi)
                    : $fallbackProducts;

                $time = (int) ($times[$activityId] ?? $times[(string) $activityId] ?? 0);

                $batch[] = [
                    'blueprint_type_id' => $typeId,
                    'activity_id' => (int) $activityId,
                    'time' => $time,
                    // json_encode required: upsert() bypasses Eloquent casts
                    'materials' => json_encode($materials),
                    'products' => json_encode($products),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $imported++;
        }

        if (!empty($batch)) {
            BlueprintActivity::upsert($batch, ['blueprint_type_id', 'activity_id'], [
                'time', 'materials', 'products', 'updated_at',
            ]);
        }

        Log::info('ProcessBlueprintDataChunk completed', [
            'chunk_size' => count($this->typeIds),
            'imported' => $imported,
            'failed' => $failed,
        ]);
    }

    /**
     * Fetch raw SDE data for a single blueprint from the Fuzzwork API.
     */
    protected function fetchFromFuzzwork(int $typeId): ?array
    {
        try {
            $response = Http::timeout(15)->get(
                'https://www.fuzzwork.co.uk/blueprint/api/blueprint.php',
                ['typeid' => $typeId]
            );

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            // The API returns a flat object: {requestedid, blueprintSkills, blueprintDetails, activityMaterials, decryptors}
            // NOT wrapped by type ID.
            if (empty($data) || empty($data['activityMaterials'])) {
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            Log::warning("Fuzzwork SDE fetch failed for type {$typeId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Partition a Fuzzwork activity material list into products and materials.
     *
     * Fuzzwork tags each entry with `maketype`, the type ID of the blueprint
     * that produces it. When that equals the blueprint being processed, the
     * entry is this blueprint's own output (common for reactions, whose product
     * is listed inside activityMaterials) and is treated as a product. Every
     * other entry is an input material.
     *
     * @param  array<int, array>  $items            Raw Fuzzwork material entries
     * @param  int                $blueprintTypeId  The blueprint being processed
     * @return array{0: array<int, array>, 1: array<int, array>}  [products, materials]
     */
    protected function partitionByMakeType(array $items, int $blueprintTypeId): array
    {
        $products = [];
        $materials = [];

        foreach ($items as $item) {
            $makeType = (int) ($item['maketype'] ?? -1);
            if ($blueprintTypeId > 0 && $makeType === $blueprintTypeId) {
                $products[] = $item;
            } else {
                $materials[] = $item;
            }
        }

        return [$products, $materials];
    }

    /**
     * Resolve a materials list from Fuzzwork format, using pre-fetched local names with ESI fallback.
     *
     * Fuzzwork returns materials as arrays of {typeid, name, quantity, maketype} objects.
     *
     * @param  array<int, array>          $items       Raw Fuzzwork material entries
     * @param  array<int, string>         $localNames  Pre-fetched type_id => name map
     * @param  EsiService                 $esi         ESI service for fallback lookups
     * @return array<int, array{type_id: int, name: string, quantity: int}>
     */
    protected function resolveTypeList(array $items, array $localNames, EsiService $esi): array
    {
        $resolved = [];

        foreach ($items as $itemInfo) {
            $itemTypeId = (int) ($itemInfo['typeid'] ?? $itemInfo['typeID'] ?? $itemInfo['type_id'] ?? 0);
            if (!$itemTypeId) {
                continue;
            }

            $name = $itemInfo['name'] ?? null;
            if (!$name) {
                $name = $localNames[$itemTypeId] ?? null;
                if (!$name) {
                    $typeInfo = $esi->getTypeInfo($itemTypeId);
                    $name = $typeInfo['name'] ?? 'Unknown';
                }
            }
            $resolved[] = [
                'type_id' => $itemTypeId,
                'name' => $name,
                'quantity' => (int) ($itemInfo['quantity'] ?? 0),
            ];
        }

        return $resolved;
    }
}
