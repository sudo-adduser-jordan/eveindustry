<?php

namespace App\Services;

use App\Models\EveType;
use App\Models\MoonScanResult;
use App\Models\MoonScanSystem;
use App\Models\TypeMaterial;

class MoonScanService
{
    /**
     * Assumed total ore volume per moon used for ISK calculations.
     * 10 million m³ is the standard assumption for moon mining yield.
     */
    public const TOTAL_MOON_M3 = 10_000_000;

    /**
     * Reprocessing efficiency applied when $pricingMode is 'refined'.
     * 85% is the practical base for moon ore without structure bonuses.
     */
    public const REFINED_EFFICIENCY = 0.85;

    /**
     * Moon ore rarity tiers used for grading (higher = rarer/more valuable).
     * These are the moon ore categories from EVE Online.
     */
    private const ORE_TIERS = [
        // R4 - Ubiquitous (cheapest)
        45490 => 1, // Zeolites
        45491 => 1, // Sylvite
        45492 => 1, // Bitumens
        45493 => 1, // Coesite
        // R8 - Common
        45494 => 2, // Cobaltite
        45495 => 2, // Euxenite
        45496 => 2, // Titanite
        45497 => 2, // Scheelite
        // R16 - Uncommon
        45498 => 3, // Otavite
        45499 => 3, // Sperrylite
        45500 => 3, // Vanadinite
        45501 => 3, // Chromite
        // R32 - Rare
        45502 => 4, // Carnotite
        45503 => 4, // Zircon
        45504 => 4, // Pollucite
        45505 => 4, // Loparite
        // R64 - Exceptional (most valuable)
        45506 => 5, // Monazite
        45507 => 5, // Xenotime
        45508 => 5, // Ytterbite
        45509 => 5, // Botite
    ];

    /**
     * Fallback volume per ore unit (m³) when the SDE has no value.
     * Moon ores are typically 10 m³/unit.
     */
    private const DEFAULT_ORE_VOLUME = 10.0;

    public function __construct(protected MarketService $marketService) {}

    /**
     * Parse pasted moon scan data and return analysed moons graded by ISK value.
     *
     * All ISK figures are based on TOTAL_MOON_M3 (2.5 million m³) of total ore
     * being available in the moon. Each ore's contribution is calculated as:
     *   units = floor(composition_fraction × TOTAL_MOON_M3 / volume_per_unit)
     *   isk   = units × effective_price_per_unit
     *
     * $pricingMode controls how effective_price_per_unit is determined:
     *   'raw'        – sell price of the raw ore unit
     *   'compressed' – sell price of the equivalent compressed ore unit
     *   'refined'    – mineral sell value at REFINED_EFFICIENCY (85%) per ore unit
     *                  = sum(mineral_qty × mineral_price) × efficiency / portion_size
     *
     * Results are persisted to the moon_scan_results table for future reference.
     *
     * @param  string  $rawData  TSV text pasted from the moon scanning window
     * @param  int  $regionId  Market region to use for pricing
     * @param  string  $pricingMode  'raw' | 'compressed' | 'refined'
     * @param  int|null  $corporationId  Corporation to associate results with
     * @return array{moons: array, errors: array}
     */
    public function analyse(
        string $rawData,
        int $regionId = MarketService::DEFAULT_REGION_ID,
        string $pricingMode = 'raw',
        ?int $corporationId = null
    ): array {
        $lines = preg_split('/\r?\n/', trim($rawData));
        $moons = [];
        $errors = [];
        $currentMoonIndex = -1;
        $oreTypeIds = [];

        // First pass: parse structure
        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line);

            // Skip the header row
            if (str_starts_with(trim($parts[0]), 'Moon') && isset($parts[1]) && trim($parts[1]) === 'Moon Product') {
                continue;
            }

            // Moon header row: first column non-empty, rest empty/absent
            if (trim($parts[0]) !== '' && (! isset($parts[1]) || trim($parts[1]) === '')) {
                $moons[] = [
                    'name' => trim($parts[0]),
                    'ores' => [],
                ];
                $currentMoonIndex = count($moons) - 1;

                continue;
            }

            // Ore row: first column empty, second column is ore name
            if (trim($parts[0]) === '' && isset($parts[1]) && trim($parts[1]) !== '') {
                if ($currentMoonIndex < 0) {
                    $errors[] = 'Ore row found before any moon header: '.trim($parts[1]);

                    continue;
                }

                $oreName = trim($parts[1]);
                $quantity = isset($parts[2]) ? (float) trim($parts[2]) : 0.0;
                $typeId = isset($parts[3]) ? (int) trim($parts[3]) : null;

                if ($typeId) {
                    $oreTypeIds[] = $typeId;
                }

                $moons[$currentMoonIndex]['ores'][] = [
                    'name' => $oreName,
                    'quantity' => $quantity,
                    'type_id' => $typeId,
                ];
            }
        }

        if (empty($moons)) {
            return ['moons' => [], 'errors' => array_merge($errors, ['No moon data found. Please paste the full moon scan output.'])];
        }

        // Fetch type names, volumes, and portion sizes from the database
        $uniqueTypeIds = array_unique(array_filter($oreTypeIds));
        $oreTypes = EveType::whereIn('type_id', $uniqueTypeIds)
            ->get(['type_id', 'name', 'volume', 'portion_size'])
            ->keyBy('type_id');

        // Resolve effective price per raw ore unit for each type
        $effectivePrices = $this->resolveEffectivePrices(
            $uniqueTypeIds, $oreTypes, $regionId, $pricingMode
        );

        // Second pass: enrich ores and calculate moon ISK values
        foreach ($moons as $moonIdx => $moon) {
            $totalIsk = 0.0;
            $maxTier = 0;

            foreach ($moon['ores'] as $oreIdx => $ore) {
                $typeId = $ore['type_id'];
                $oreType = $typeId ? ($oreTypes[$typeId] ?? null) : null;

                // Use DB name if available, fall back to parsed name
                if ($oreType) {
                    $moons[$moonIdx]['ores'][$oreIdx]['name'] = $oreType->name;
                }

                // Volume per unit (m³); fall back to 10 m³ if not in DB
                $volumePerUnit = ($oreType && $oreType->volume > 0)
                    ? (float) $oreType->volume
                    : self::DEFAULT_ORE_VOLUME;

                $oreM3 = $ore['quantity'] * self::TOTAL_MOON_M3;
                $oreUnits = (int) floor($oreM3 / $volumePerUnit);

                $price = $typeId ? ($effectivePrices[$typeId] ?? 0.0) : 0.0;
                $tier = $typeId ? (self::ORE_TIERS[$typeId] ?? 0) : 0;
                $oreIsk = $oreUnits * $price;
                $totalIsk += $oreIsk;

                $moons[$moonIdx]['ores'][$oreIdx]['price_per_unit'] = $price;
                $moons[$moonIdx]['ores'][$oreIdx]['tier'] = $tier;
                $moons[$moonIdx]['ores'][$oreIdx]['ore_units'] = $oreUnits;
                $moons[$moonIdx]['ores'][$oreIdx]['value_contribution'] = $oreIsk;

                if ($tier > $maxTier) {
                    $maxTier = $tier;
                }
            }

            $moons[$moonIdx]['total_value'] = $totalIsk;
            $moons[$moonIdx]['max_tier'] = $maxTier;
            $moons[$moonIdx]['grade'] = $this->gradeFromTierAndValue($maxTier, $totalIsk);
        }

        // Sort moons by total ISK value descending
        usort($moons, fn ($a, $b) => $b['total_value'] <=> $a['total_value']);

        // Persist results to the database for future reference; get back the moon IDs
        $moonIdMap = $this->persistResults($moons, $regionId, $pricingMode, $corporationId);

        // Attach the DB record IDs and occupation defaults to the returned moons
        foreach ($moons as &$moon) {
            $moon['id'] = $moonIdMap[$moon['name']] ?? null;
            $moon['occupied'] = false;
            $moon['occupying_corporation_id'] = null;
            $moon['occupying_corporation_name'] = null;
        }
        unset($moon);

        return ['moons' => $moons, 'errors' => $errors];
    }

    /**
     * Re-price a collection of stored moon results using a different pricing mode.
     *
     * Because the original ore `quantity` (composition fraction) and `ore_units` are
     * already persisted, we only need to re-fetch market prices and recalculate
     * `price_per_unit`, `value_contribution`, `total_value`, and `grade`.
     *
     * @param  \Illuminate\Support\Collection<int,MoonScanResult>  $rows
     * @param  string  $pricingMode  'raw' | 'compressed' | 'refined'
     * @return array<int, array> Enriched moon array, sorted by total_value desc
     */
    public function repriceSystemMoons(\Illuminate\Support\Collection $rows, int $regionId, string $pricingMode): array
    {
        // Collect all unique ore type IDs from stored ores
        $uniqueTypeIds = [];
        foreach ($rows as $row) {
            foreach ($row->ores ?? [] as $ore) {
                if (! empty($ore['type_id'])) {
                    $uniqueTypeIds[] = (int) $ore['type_id'];
                }
            }
        }
        $uniqueTypeIds = array_unique($uniqueTypeIds);

        $oreTypes = ! empty($uniqueTypeIds)
            ? EveType::whereIn('type_id', $uniqueTypeIds)
                ->get(['type_id', 'name', 'volume', 'portion_size'])
                ->keyBy('type_id')
            : collect();

        $effectivePrices = $this->resolveEffectivePrices($uniqueTypeIds, $oreTypes, $regionId, $pricingMode);

        $moons = [];
        foreach ($rows as $row) {
            $totalIsk = 0.0;
            $maxTier = 0;
            $ores = [];

            foreach ($row->ores ?? [] as $ore) {
                $typeId = isset($ore['type_id']) ? (int) $ore['type_id'] : null;
                $tier = (int) ($ore['tier'] ?? 0);

                // Prefer stored ore_units; fall back to recomputing from quantity
                if (isset($ore['ore_units']) && $ore['ore_units'] > 0) {
                    $oreUnits = (int) $ore['ore_units'];
                } else {
                    $quantity = (float) ($ore['quantity'] ?? 0);
                    $oreType = $typeId ? ($oreTypes[$typeId] ?? null) : null;
                    $volumePerUnit = ($oreType && $oreType->volume > 0)
                        ? (float) $oreType->volume
                        : self::DEFAULT_ORE_VOLUME;
                    $oreUnits = (int) floor($quantity * self::TOTAL_MOON_M3 / $volumePerUnit);
                }

                $price = $typeId ? ($effectivePrices[$typeId] ?? 0.0) : 0.0;
                $oreIsk = $oreUnits * $price;
                $totalIsk += $oreIsk;

                if ($tier > $maxTier) {
                    $maxTier = $tier;
                }

                $ores[] = array_merge($ore, [
                    'ore_units' => $oreUnits,
                    'price_per_unit' => $price,
                    'value_contribution' => $oreIsk,
                ]);
            }

            $moons[] = [
                'id' => $row->id,
                'name' => $row->moon_name,
                'grade' => $this->gradeFromTierAndValue($maxTier, $totalIsk),
                'total_value' => $totalIsk,
                'ores' => $ores,
                'occupied' => (bool) $row->occupied,
                'occupying_corporation_id' => $row->occupying_corporation_id,
                'occupying_corporation_name' => $row->occupying_corporation_name,
            ];
        }

        usort($moons, fn ($a, $b) => $b['total_value'] <=> $a['total_value']);

        return $moons;
    }

    /**
     * Resolve an effective price-per-raw-unit for each ore type ID based on
     * the chosen pricing mode.
     *
     * 'raw'        – raw ore sell price (1 unit)
     * 'compressed' – sell price of the compressed variant
     * 'refined'    – mineral value at REFINED_EFFICIENCY per raw ore unit
     *                = floor(material_qty × efficiency) / portion_size × mineral_price
     *
     * @param  array<int>  $uniqueTypeIds
     * @param  \Illuminate\Support\Collection<int,EveType>  $oreTypes
     * @return array<int, float> keyed by raw ore type_id
     */
    private function resolveEffectivePrices(
        array $uniqueTypeIds,
        $oreTypes,
        int $regionId,
        string $pricingMode
    ): array {
        if ($pricingMode === 'compressed') {
            // Look up compressed type IDs by name
            $compressedNames = $oreTypes
                ->map(fn ($t) => 'Compressed '.$t->name)
                ->values()
                ->all();

            $compressedTypes = EveType::whereIn('name', $compressedNames)
                ->get(['type_id', 'name'])
                ->keyBy('name');

            $compressedTypeIdMap = [];
            foreach ($oreTypes as $rawTypeId => $type) {
                $compressedName = 'Compressed '.$type->name;
                if (isset($compressedTypes[$compressedName])) {
                    $compressedTypeIdMap[$rawTypeId] = $compressedTypes[$compressedName]->type_id;
                }
            }

            $compressedIds = array_unique(array_values($compressedTypeIdMap));
            $prices = ! empty($compressedIds)
                ? $this->marketService->getSellPrices($compressedIds, $regionId)
                : [];

            $result = [];
            foreach ($uniqueTypeIds as $typeId) {
                $compressedId = $compressedTypeIdMap[$typeId] ?? null;
                $result[$typeId] = $compressedId ? ($prices[$compressedId] ?? 0.0) : 0.0;
            }

            return $result;
        }

        if ($pricingMode === 'refined') {
            // Load TypeMaterial rows for all ore type IDs
            $materialRows = TypeMaterial::whereIn('type_id', $uniqueTypeIds)->get();

            // Collect all unique mineral type IDs
            $mineralTypeIds = $materialRows->pluck('material_type_id')->unique()->values()->all();
            $mineralPrices = ! empty($mineralTypeIds)
                ? $this->marketService->getSellPrices($mineralTypeIds, $regionId)
                : [];

            // Group material rows by ore type_id
            $materialsByOre = $materialRows->groupBy('type_id');

            $result = [];
            foreach ($uniqueTypeIds as $typeId) {
                $oreType = $oreTypes[$typeId] ?? null;
                $portionSize = max((int) ($oreType?->portion_size ?? 1), 1);
                $materials = $materialsByOre->get($typeId, collect());

                // Mineral value yielded per reprocessing batch (one portion_size units).
                // EVE floors each material quantity independently before applying yield,
                // so: refined_qty = floor(base_qty × efficiency). This matches in-game behaviour.
                $batchMineralValue = 0.0;
                foreach ($materials as $mat) {
                    $refinedQty = (int) floor($mat->quantity * self::REFINED_EFFICIENCY);
                    $mineralPrice = $mineralPrices[$mat->material_type_id] ?? 0.0;
                    $batchMineralValue += $refinedQty * $mineralPrice;
                }

                // Convert to per-single-unit value
                $result[$typeId] = $portionSize > 0 ? $batchMineralValue / $portionSize : 0.0;
            }

            return $result;
        }

        // Default: 'raw' – sell price of the raw ore
        $prices = ! empty($uniqueTypeIds)
            ? $this->marketService->getSellPrices($uniqueTypeIds, $regionId)
            : [];

        $result = [];
        foreach ($uniqueTypeIds as $typeId) {
            $result[$typeId] = $prices[$typeId] ?? 0.0;
        }

        return $result;
    }

    /**
     * Persist each moon result from a scan to the database.
     *
     * Systems are upserted into moon_scan_systems (one record per system+corporation).
     * Individual moons are upserted into moon_scan_results keyed on (system_id, moon_name),
     * so that scans of different moons in the same system by different corp members are
     * combined into a complete picture rather than overwriting each other.
     *
     * @param  array<int, array>  $moons  Sorted moon array after enrichment
     * @param  int|null  $corporationId  Corporation to scope the results to
     * @return array<string, int> Map of moon_name => moon_scan_result ID
     */
    private function persistResults(array $moons, int $regionId, string $pricingMode, ?int $corporationId = null): array
    {
        $now = now();
        $systemCache = []; // (system_name + corporation_id) => system_id
        $moonIdMap = [];   // moon_name => moon_scan_result ID

        foreach ($moons as $moon) {
            $moonName = $moon['name'];
            $planetName = $this->parsePlanetName($moonName);
            $systemName = $this->parseSystemName($planetName);

            if (! $systemName) {
                \Illuminate\Support\Facades\Log::warning(
                    "MoonScanService: could not parse system name for moon {$moonName}, skipping."
                );

                continue;
            }

            // Upsert the system record (one per system+corporation)
            $cacheKey = $systemName.'|'.($corporationId ?? '');
            if (! isset($systemCache[$cacheKey])) {
                try {
                    $system = MoonScanSystem::updateOrCreate(
                        ['system_name' => $systemName, 'corporation_id' => $corporationId],
                        [
                            'region_id' => $regionId,
                            'pricing_mode' => $pricingMode,
                            'last_scanned_at' => $now,
                        ]
                    );
                    $systemCache[$cacheKey] = $system->id;
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        "MoonScanService: failed to upsert system {$systemName}: ".$e->getMessage()
                    );

                    continue;
                }
            }

            $systemId = $systemCache[$cacheKey];

            // Store a compact version of the ores (omit redundant computed fields)
            $oresCompact = array_map(fn ($ore) => [
                'name' => $ore['name'],
                'type_id' => $ore['type_id'],
                'quantity' => $ore['quantity'],
                'tier' => $ore['tier'],
                'ore_units' => $ore['ore_units'],
                'price_per_unit' => $ore['price_per_unit'],
                'value_contribution' => $ore['value_contribution'],
            ], array_values($moon['ores']));

            try {
                // Upsert moon record (one per system+moon_name)
                $record = MoonScanResult::updateOrCreate(
                    ['system_id' => $systemId, 'moon_name' => $moonName],
                    [
                        'planet_name' => $planetName,
                        'grade' => $moon['grade'],
                        'ores' => $oresCompact,
                        'total_value' => (int) round($moon['total_value']),
                        'scanned_at' => $now,
                    ]
                );
                $moonIdMap[$moonName] = $record->id;
            } catch (\Exception $e) {
                // Non-critical – log and continue so the user still gets results
                \Illuminate\Support\Facades\Log::warning(
                    "MoonScanService: failed to persist result for {$moonName}: ".$e->getMessage()
                );
            }
        }

        return $moonIdMap;
    }

    /**
     * Extract the planet name from a moon name.
     * EVE moon names follow the pattern "System Planet - Moon N"
     * e.g. "Aldali II - Moon 12" → "Aldali II"
     */
    private function parsePlanetName(string $moonName): ?string
    {
        $pos = strpos($moonName, ' - Moon');
        if ($pos !== false) {
            return trim(substr($moonName, 0, $pos));
        }

        // Also handle " - Asteroid Belt" / " - Planet" variants gracefully
        $pos = strpos($moonName, ' - ');
        if ($pos !== false) {
            return trim(substr($moonName, 0, $pos));
        }

        return null;
    }

    /**
     * Extract the solar system name from a planet name.
     * Planet names are "System_Name Roman_Numeral", so strip the trailing ordinal.
     * e.g. "Aldali II" → "Aldali"
     */
    private function parseSystemName(?string $planetName): ?string
    {
        if (! $planetName) {
            return null;
        }

        // Strip a trailing Roman numeral (handles I–LXXX; EVE planets go up to around XXVII)
        $cleaned = preg_replace('/\s+[IVXLC]+$/i', '', $planetName);

        return trim($cleaned) ?: $planetName;
    }

    /**
     * Derive a letter grade (S, A, B, C, D) for a moon based on its highest ore
     * tier and the combined market value of its composition.
     */
    private function gradeFromTierAndValue(int $maxTier, float $totalValue): string
    {
        if ($maxTier >= 5) {
            return 'S';
        }
        if ($maxTier >= 4) {
            return 'A';
        }
        if ($maxTier >= 3) {
            return 'B';
        }
        if ($maxTier >= 2) {
            return 'C';
        }

        return 'D';
    }
}
