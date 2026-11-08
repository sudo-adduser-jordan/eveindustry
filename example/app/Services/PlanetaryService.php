<?php

namespace App\Services;

use App\Models\Character;
use App\Models\EveType;
use Illuminate\Support\Facades\Cache;

class PlanetaryService
{
    /**
     * Known PI pin type categories based on EVE Online type IDs.
     * Extractor Control Units (ECUs), Processors, Storage, Launchpads, Command Centers.
     */
    public const PIN_CATEGORY_EXTRACTOR = 'extractor';

    public const PIN_CATEGORY_BASIC_FACTORY = 'basic_factory';

    public const PIN_CATEGORY_ADVANCED_FACTORY = 'advanced_factory';

    public const PIN_CATEGORY_HIGHTECH_FACTORY = 'hightech_factory';

    public const PIN_CATEGORY_STORAGE = 'storage';

    public const PIN_CATEGORY_LAUNCHPAD = 'launchpad';

    public const PIN_CATEGORY_COMMAND_CENTER = 'command_center';

    public const PIN_CATEGORY_UNKNOWN = 'unknown';

    /**
     * Map EVE type IDs to pin categories.
     * These IDs cover the common PI structures.
     *
     * @see https://github.com/calli-eve/eve-pi/blob/main/src/const.ts
     */
    protected const PIN_TYPE_MAP = [
        // Extractor Control Units
        2848 => self::PIN_CATEGORY_EXTRACTOR,  // Barren ECU
        3060 => self::PIN_CATEGORY_EXTRACTOR,  // Gas ECU
        3061 => self::PIN_CATEGORY_EXTRACTOR,  // Ice ECU
        3062 => self::PIN_CATEGORY_EXTRACTOR,  // Lava ECU
        3063 => self::PIN_CATEGORY_EXTRACTOR,  // Oceanic ECU
        3064 => self::PIN_CATEGORY_EXTRACTOR,  // Plasma ECU
        3067 => self::PIN_CATEGORY_EXTRACTOR,  // Storm ECU
        3068 => self::PIN_CATEGORY_EXTRACTOR,  // Temperate ECU

        // Basic Industry Facilities (P0 → P1 processors)
        2469 => self::PIN_CATEGORY_BASIC_FACTORY,  // Lava Basic
        2471 => self::PIN_CATEGORY_BASIC_FACTORY,  // Plasma Basic
        2473 => self::PIN_CATEGORY_BASIC_FACTORY,  // Barren Basic
        2481 => self::PIN_CATEGORY_BASIC_FACTORY,  // Temperate Basic
        2483 => self::PIN_CATEGORY_BASIC_FACTORY,  // Storm Basic
        2490 => self::PIN_CATEGORY_BASIC_FACTORY,  // Oceanic Basic
        2492 => self::PIN_CATEGORY_BASIC_FACTORY,  // Gas Basic
        2493 => self::PIN_CATEGORY_BASIC_FACTORY,  // Ice Basic

        // Advanced Industry Facilities (P1 → P2 processors)
        2470 => self::PIN_CATEGORY_ADVANCED_FACTORY,  // Lava Advanced
        2472 => self::PIN_CATEGORY_ADVANCED_FACTORY,  // Plasma Advanced
        2474 => self::PIN_CATEGORY_ADVANCED_FACTORY,  // Barren Advanced
        2480 => self::PIN_CATEGORY_ADVANCED_FACTORY,  // Temperate Advanced
        2484 => self::PIN_CATEGORY_ADVANCED_FACTORY,  // Storm Advanced
        2485 => self::PIN_CATEGORY_ADVANCED_FACTORY,  // Oceanic Advanced
        2491 => self::PIN_CATEGORY_ADVANCED_FACTORY,  // Ice Advanced
        2494 => self::PIN_CATEGORY_ADVANCED_FACTORY,  // Gas Advanced

        // High-Tech Production Plants (P2 → P3/P4 processors)
        2475 => self::PIN_CATEGORY_HIGHTECH_FACTORY,  // Barren High-Tech
        2482 => self::PIN_CATEGORY_HIGHTECH_FACTORY,  // Temperate High-Tech

        // Storage Facilities
        2257 => self::PIN_CATEGORY_STORAGE,  // Ice Storage
        2535 => self::PIN_CATEGORY_STORAGE,  // Oceanic Storage
        2536 => self::PIN_CATEGORY_STORAGE,  // Gas Storage
        2541 => self::PIN_CATEGORY_STORAGE,  // Barren Storage
        2558 => self::PIN_CATEGORY_STORAGE,  // Lava Storage
        2560 => self::PIN_CATEGORY_STORAGE,  // Plasma Storage
        2561 => self::PIN_CATEGORY_STORAGE,  // Storm Storage
        2562 => self::PIN_CATEGORY_STORAGE,  // Temperate Storage

        // Launchpads
        2256 => self::PIN_CATEGORY_LAUNCHPAD,  // Temperate Launchpad
        2542 => self::PIN_CATEGORY_LAUNCHPAD,  // Oceanic Launchpad
        2543 => self::PIN_CATEGORY_LAUNCHPAD,  // Gas Launchpad
        2544 => self::PIN_CATEGORY_LAUNCHPAD,  // Barren Launchpad
        2552 => self::PIN_CATEGORY_LAUNCHPAD,  // Ice Launchpad
        2555 => self::PIN_CATEGORY_LAUNCHPAD,  // Lava Launchpad
        2556 => self::PIN_CATEGORY_LAUNCHPAD,  // Plasma Launchpad
        2557 => self::PIN_CATEGORY_LAUNCHPAD,  // Storm Launchpad

        // Command Centers
        2254 => self::PIN_CATEGORY_COMMAND_CENTER,
        2505 => self::PIN_CATEGORY_COMMAND_CENTER,
        2506 => self::PIN_CATEGORY_COMMAND_CENTER,
        2507 => self::PIN_CATEGORY_COMMAND_CENTER,
        2508 => self::PIN_CATEGORY_COMMAND_CENTER,
        2509 => self::PIN_CATEGORY_COMMAND_CENTER,
        2510 => self::PIN_CATEGORY_COMMAND_CENTER,
        2511 => self::PIN_CATEGORY_COMMAND_CENTER,
    ];

    /** Storage capacity in m³ for different pin types. */
    public const LAUNCHPAD_CAPACITY = 10000;

    public const STORAGE_CAPACITY = 12000;

    /** Default volume per unit (m³) when actual volume data is unavailable. */
    public const DEFAULT_VOLUME = 0.01;

    /**
     * PI extraction decay/noise constants from EVE Dogma attributes.
     *
     * @see https://developers.eveonline.com/docs/guides/pi/
     */
    public const DECAY_FACTOR = 0.012;  // Dogma attribute 1683

    public const NOISE_FACTOR = 0.8;    // Dogma attribute 1687

    /** Cache TTL for per-character planetary data: 20 minutes in seconds. */
    public const PLANETARY_CACHE_TTL = 1200;

    /** PI product tier labels. */
    public const TIER_LABELS = [
        0 => 'R0 – Raw',
        1 => 'P1 – Processed',
        2 => 'P2 – Refined',
        3 => 'P3 – Specialized',
        4 => 'P4 – Advanced',
    ];

    /**
     * Known PI commodity volumes (m³ per unit).
     * This serves as a fallback when database/ESI data is unavailable.
     *
     * @see https://github.com/calli-eve/eve-pi/blob/main/src/const.ts
     */
    protected const PI_COMMODITY_VOLUMES = [
        // P0 - Raw Materials (0.01 m³/unit)
        2073 => 0.01, // Microorganisms
        2267 => 0.01, // Base Metals
        2268 => 0.01, // Aqueous Liquids
        2270 => 0.01, // Noble Metals
        2272 => 0.01, // Heavy Metals
        2286 => 0.01, // Planktic Colonies
        2287 => 0.01, // Complex Organisms
        2288 => 0.01, // Carbon Compounds
        2305 => 0.01, // Autotrophs
        2306 => 0.01, // Non-CS Crystals
        2307 => 0.01, // Felsic Magma
        2308 => 0.01, // Suspended Plasma
        2309 => 0.01, // Ionic Solutions
        2310 => 0.01, // Noble Gas
        2311 => 0.01, // Reactive Gas

        // P1 - Processed Materials (0.19 m³/unit)
        2389 => 0.19, // Plasmoids
        2390 => 0.19, // Electrolytes
        2392 => 0.19, // Oxidizing Compound
        2393 => 0.19, // Bacteria
        2395 => 0.19, // Proteins
        2396 => 0.19, // Biofuels
        2397 => 0.19, // Industrial Fibers
        2398 => 0.19, // Reactive Metals
        2399 => 0.19, // Precious Metals
        2400 => 0.19, // Toxic Metals
        2401 => 0.19, // Chiral Structures
        3645 => 0.19, // Water
        3683 => 0.19, // Oxygen
        3779 => 0.19, // Biomass
        9828 => 0.19, // Silicon

        // P2 - Refined Commodities (1.5 m³/unit)
        44 => 1.5,    // Enriched Uranium
        2312 => 1.5,  // Supertensile Plastics
        2317 => 1.5,  // Oxides
        2319 => 1.5,  // Test Cultures
        2321 => 1.5,  // Polyaramids
        2327 => 1.5,  // Microfiber Shielding
        2328 => 1.5,  // Water-Cooled CPU
        2329 => 1.5,  // Biocells
        2463 => 1.5,  // Nanites
        3689 => 1.5,  // Mechanical Parts
        3691 => 1.5,  // Synthetic Oil
        3693 => 1.5,  // Fertilizer
        3695 => 1.5,  // Polytextiles
        3697 => 1.5,  // Silicate Glass
        3725 => 1.5,  // Livestock
        3775 => 1.5,  // Viral Agent
        3828 => 1.5,  // Construction Blocks
        9830 => 1.5,  // Rocket Fuel
        9832 => 1.5,  // Coolant
        9836 => 1.5,  // Consumer Electronics
        9838 => 1.5,  // Superconductors
        9840 => 1.5,  // Transmitter
        9842 => 1.5,  // Miniature Electronics
        15317 => 1.5, // Genetically Enhanced Livestock

        // P3 - Specialized Commodities (6 m³/unit)
        2344 => 6.0,  // Condensates
        2345 => 6.0,  // Camera Drones
        2346 => 6.0,  // Synthetic Synapses
        2348 => 6.0,  // Gel-Matrix Biopaste
        2349 => 6.0,  // Supercomputers
        2351 => 6.0,  // Smartfab Units
        2352 => 6.0,  // Nuclear Reactors
        2354 => 6.0,  // Neocoms
        2358 => 6.0,  // Biotech Research Reports
        2360 => 6.0,  // Industrial Explosives
        2361 => 6.0,  // Hermetic Membranes
        2366 => 6.0,  // Hazmat Detection Systems
        2367 => 6.0,  // Cryoprotectant Solution
        9834 => 6.0,  // Guidance Systems
        9846 => 6.0,  // Planetary Vehicles
        9848 => 6.0,  // Robotics
        12836 => 6.0, // Transcranial Microcontrollers
        17136 => 6.0, // Ukomi Superconductors
        17392 => 6.0, // Data Chips
        17898 => 6.0, // High-Tech Transmitters
        28974 => 6.0, // Vaccines

        // P4 - Advanced Commodities (100 m³/unit)
        2867 => 100.0, // Broadcast Node
        2868 => 100.0, // Integrity Response Drones
        2869 => 100.0, // Nano-Factory
        2870 => 100.0, // Organic Mortar Applicators
        2871 => 100.0, // Recursive Computing Module
        2872 => 100.0, // Self-Harmonizing Power Core
        2875 => 100.0, // Sterile Conduits
        2876 => 100.0, // Wetware Mainframe
    ];

    public function __construct(protected EsiService $esi) {}

    /**
     * Get full PI data across all characters for a user.
     */
    public function getPlanetaryDataForUser(int $userId): array
    {
        $characters = RefreshEligibility::characters($userId, 'pi')->get();
        $allCharacters = [];
        $totalPlanets = 0;
        $totalExtractors = 0;
        $totalFactories = 0;
        $allOutputs = [];
        $alerts = [];

        foreach ($characters as $character) {
            $charData = $this->getCharacterPlanetaryData($character);
            if ($charData === null) {
                throw new \RuntimeException('Planetary refresh did not complete');
            }

            $allCharacters[] = $charData;
            $totalPlanets += count($charData['planets']);

            foreach ($charData['planets'] as $planet) {
                $totalExtractors += $planet['summary']['extractor_count'];
                $totalFactories += $planet['summary']['factory_count'];

                foreach ($planet['outputs'] as $output) {
                    $typeId = $output['type_id'];
                    if (! isset($allOutputs[$typeId])) {
                        $allOutputs[$typeId] = [
                            'type_id' => $typeId,
                            'name' => $output['name'],
                            'total_per_hour' => 0,
                            'planets' => 0,
                        ];
                    }
                    $allOutputs[$typeId]['total_per_hour'] += $output['per_hour'];
                    $allOutputs[$typeId]['planets']++;
                }

                // Merge alerts
                foreach ($planet['alerts'] as $alert) {
                    $alerts[] = array_merge($alert, [
                        'character_name' => $charData['character_name'],
                        'planet_name' => $planet['planet_name'],
                    ]);
                }
            }
        }

        // Sort outputs by total production rate
        usort($allOutputs, fn ($a, $b) => $b['total_per_hour'] <=> $a['total_per_hour']);

        return [
            'characters' => $allCharacters,
            'summary' => [
                'total_planets' => $totalPlanets,
                'total_extractors' => $totalExtractors,
                'total_factories' => $totalFactories,
                'total_characters' => count($allCharacters),
            ],
            'outputs' => array_values($allOutputs),
            'alerts' => $alerts,
        ];
    }

    /**
     * Get PI data for a single character.
     */
    public function getCharacterPlanetaryData(Character $character): ?array
    {
        if (! RefreshEligibility::character($character, RefreshEligibility::PI_SCOPES)) {
            return null;
        }

        $cacheKey = "planetary.character.{$character->character_id}";

        return Cache::remember($cacheKey, self::PLANETARY_CACHE_TTL, function () use ($character) {
            return $this->fetchCharacterPlanetaryData($character);
        });
    }

    /**
     * Fetch and analyze PI data for a single character (uncached).
     */
    protected function fetchCharacterPlanetaryData(Character $character): ?array
    {
        $planets = $this->esi->getCharacterPlanets($character);

        if ($planets === null) {
            return null;
        }

        $planetData = [];
        foreach ($planets as $colony) {
            $planetId = $colony['planet_id'] ?? null;
            if (! $planetId) {
                continue;
            }

            $details = $this->esi->getCharacterPlanetDetails($character, $planetId);
            if ($details === null) {
                throw new \RuntimeException('Planetary colony refresh did not complete');
            }
            $planetInfo = $this->esi->getPlanetInfo($planetId);

            $planetName = $planetInfo['name'] ?? "Planet {$planetId}";
            $planetType = $colony['planet_type'] ?? 'unknown';

            $analysis = $this->analyzePlanet($details, $colony);

            $planetData[] = [
                'planet_id' => $planetId,
                'planet_name' => $planetName,
                'planet_type' => $planetType,
                'upgrade_level' => $colony['upgrade_level'] ?? 0,
                'num_pins' => $colony['num_pins'] ?? 0,
                'last_update' => $colony['last_update'] ?? null,
                'summary' => $analysis['summary'],
                'pins' => $analysis['pins'],
                'outputs' => $analysis['outputs'],
                'inputs' => $analysis['inputs'],
                'alerts' => $analysis['alerts'],
                'routes' => $analysis['routes'],
            ];
        }

        return [
            'character_id' => $character->character_id,
            'character_name' => $character->name,
            'portrait_url' => $character->portrait_url,
            'planets' => $planetData,
        ];
    }

    /**
     * Analyze a single planet's pins, routes, and production.
     */
    public function analyzePlanet(?array $details, array $colony): array
    {
        $pins = $details['pins'] ?? [];
        $routes = $details['routes'] ?? [];
        $links = $details['links'] ?? [];

        $extractorCount = 0;
        $factoryCount = 0;
        $storageCount = 0;
        $launchpadCount = 0;

        $pinDetails = [];
        $outputs = [];
        $inputs = [];
        $alerts = [];

        $extractorOutputsPerHour = [];
        $factoryInputsPerHour = [];
        $factoryOutputsPerHour = [];

        foreach ($pins as $pin) {
            $typeId = $pin['type_id'] ?? 0;
            $category = $this->categorizePinType($typeId);
            $typeName = $this->resolveTypeName($typeId);

            $pinInfo = [
                'pin_id' => $pin['pin_id'] ?? 0,
                'type_id' => $typeId,
                'type_name' => $typeName,
                'category' => $category,
                'contents' => $pin['contents'] ?? [],
            ];

            // Process extractors
            if (isset($pin['extractor_details'])) {
                $extractorCount++;
                $extractorDetails = $pin['extractor_details'];
                $productTypeId = $extractorDetails['product_type_id'] ?? 0;
                $qtyPerCycle = $extractorDetails['qty_per_cycle'] ?? 0;
                $cycleTime = $extractorDetails['cycle_time'] ?? 0;
                $productName = $this->resolveTypeName($productTypeId);
                $perHour = $cycleTime > 0 ? ($qtyPerCycle / $cycleTime) * 3600 : 0;

                $expiryTime = $pin['expiry_time'] ?? null;
                $installTime = $pin['install_time'] ?? null;
                $isActive = $expiryTime && strtotime($expiryTime) > time();

                // Calculate decay-adjusted extraction using official PI formula
                $duration = 0;
                if ($expiryTime && $installTime) {
                    $duration = max(0, strtotime($expiryTime) - strtotime($installTime));
                }
                $extractorTotals = $this->calculateExtractorTotals($qtyPerCycle, $cycleTime, $duration);
                $avgPerHour = $extractorTotals['avg_per_hour'];

                $pinInfo['extractor'] = [
                    'product_type_id' => $productTypeId,
                    'product_name' => $productName,
                    'qty_per_cycle' => $qtyPerCycle,
                    'cycle_time' => $cycleTime,
                    'per_hour' => round($perHour, 2),
                    'avg_per_hour' => $avgPerHour,
                    'total_output' => $extractorTotals['total_output'],
                    'total_cycles' => $extractorTotals['total_cycles'],
                    'expiry_time' => $expiryTime,
                    'install_time' => $installTime,
                    'is_active' => $isActive,
                ];

                if ($productTypeId) {
                    $extractorOutputsPerHour[$productTypeId] = ($extractorOutputsPerHour[$productTypeId] ?? 0) + $perHour;
                }

                // Check for expired extractors
                if ($expiryTime && strtotime($expiryTime) < time()) {
                    $alerts[] = [
                        'type' => 'extractor_expired',
                        'severity' => 'warning',
                        'message' => "Extractor for {$productName} has expired",
                        'pin_id' => $pin['pin_id'] ?? 0,
                    ];
                }
            }

            // Process factory pins (processors)
            if (isset($pin['schematic_id']) && $pin['schematic_id'] > 0) {
                $factoryCount++;
                $schematicId = $pin['schematic_id'];
                $schematic = $this->esi->getPlanetarySchematic($schematicId);

                if ($schematic) {
                    $schematicName = $schematic['schematic_name'] ?? 'Unknown Schematic';
                    $cycleTime = $schematic['cycle_time'] ?? 0;

                    $schematicInputs = [];
                    $schematicOutputs = [];

                    // Parse schematic inputs/outputs from the ESI response
                    if (! empty($schematic['schematic_name'])) {
                        // The output type name matches the schematic name in most cases
                        $pinInfo['factory'] = [
                            'schematic_id' => $schematicId,
                            'schematic_name' => $schematicName,
                            'cycle_time' => $cycleTime,
                        ];
                    }
                }
            }

            // Count storage and launchpads + compute storage usage
            if ($category === self::PIN_CATEGORY_STORAGE) {
                $storageCount++;
                $pinInfo['storage'] = $this->computeStorageUsage($pin, self::STORAGE_CAPACITY);
            }
            if ($category === self::PIN_CATEGORY_LAUNCHPAD) {
                $launchpadCount++;
                $pinInfo['storage'] = $this->computeStorageUsage($pin, self::LAUNCHPAD_CAPACITY);
            }

            $pinDetails[] = $pinInfo;
        }

        // Build output list from routes
        $routeOutputs = $this->analyzeRoutes($routes, $pins);

        // Merge extractor outputs into the main outputs list
        foreach ($extractorOutputsPerHour as $typeId => $perHour) {
            $name = $this->resolveTypeName($typeId);
            $outputs[] = [
                'type_id' => $typeId,
                'name' => $name,
                'per_hour' => round($perHour, 2),
                'source' => 'extractor',
            ];
        }

        return [
            'summary' => [
                'extractor_count' => $extractorCount,
                'factory_count' => $factoryCount,
                'storage_count' => $storageCount,
                'launchpad_count' => $launchpadCount,
                'total_pins' => count($pins),
                'total_routes' => count($routes),
                'total_links' => count($links),
            ],
            'pins' => $pinDetails,
            'outputs' => $outputs,
            'inputs' => $inputs,
            'alerts' => $alerts,
            'routes' => $routeOutputs,
        ];
    }

    /**
     * Analyze routes on a planet to determine material flow.
     */
    public function analyzeRoutes(array $routes, array $pins): array
    {
        $routeInfo = [];

        foreach ($routes as $route) {
            $contentTypeId = $route['content_type_id'] ?? 0;
            $quantity = $route['quantity'] ?? 0;
            $sourcePinId = $route['source_pin_id'] ?? 0;
            $destPinId = $route['destination_pin_id'] ?? 0;

            $routeInfo[] = [
                'content_type_id' => $contentTypeId,
                'content_name' => $this->resolveTypeName($contentTypeId),
                'quantity' => $quantity,
                'source_pin_id' => $sourcePinId,
                'destination_pin_id' => $destPinId,
            ];
        }

        return $routeInfo;
    }

    /**
     * Categorize a pin by its type ID.
     */
    public function categorizePinType(int $typeId): string
    {
        // Check the static map first
        if (isset(self::PIN_TYPE_MAP[$typeId])) {
            return self::PIN_TYPE_MAP[$typeId];
        }

        // Fallback: try to infer from type name
        $typeName = $this->resolveTypeName($typeId);
        $lower = strtolower($typeName);

        if (str_contains($lower, 'extractor')) {
            return self::PIN_CATEGORY_EXTRACTOR;
        }
        if (str_contains($lower, 'basic') && str_contains($lower, 'industry')) {
            return self::PIN_CATEGORY_BASIC_FACTORY;
        }
        if (str_contains($lower, 'advanced') && str_contains($lower, 'industry')) {
            return self::PIN_CATEGORY_ADVANCED_FACTORY;
        }
        if (str_contains($lower, 'high') && str_contains($lower, 'tech')) {
            return self::PIN_CATEGORY_HIGHTECH_FACTORY;
        }
        if (str_contains($lower, 'storage')) {
            return self::PIN_CATEGORY_STORAGE;
        }
        if (str_contains($lower, 'launchpad')) {
            return self::PIN_CATEGORY_LAUNCHPAD;
        }
        if (str_contains($lower, 'command') && str_contains($lower, 'center')) {
            return self::PIN_CATEGORY_COMMAND_CENTER;
        }

        return self::PIN_CATEGORY_UNKNOWN;
    }

    /**
     * Build a factory capacity analysis for the PI data.
     */
    public function buildCapacityAnalysis(array $planetaryData): array
    {
        $capacity = [];

        foreach ($planetaryData['characters'] as $charData) {
            foreach ($charData['planets'] as $planet) {
                $factoryCount = $planet['summary']['factory_count'];
                $extractorCount = $planet['summary']['extractor_count'];

                // Check if there is a capacity imbalance
                $status = 'balanced';
                if ($extractorCount > 0 && $factoryCount === 0) {
                    $status = 'under_capacity';
                } elseif ($factoryCount > 0 && $extractorCount === 0) {
                    $status = 'idle_factories';
                } elseif ($extractorCount > 0 && $factoryCount > 0) {
                    // Rough heuristic: each extractor can typically feed 2-3 basic factories
                    $ratio = $factoryCount / max($extractorCount, 1);
                    if ($ratio < 1.5) {
                        $status = 'under_capacity';
                    } elseif ($ratio > 4) {
                        $status = 'over_capacity';
                    }
                }

                $capacity[] = [
                    'character_name' => $charData['character_name'],
                    'planet_name' => $planet['planet_name'],
                    'planet_type' => $planet['planet_type'],
                    'extractor_count' => $extractorCount,
                    'factory_count' => $factoryCount,
                    'status' => $status,
                ];
            }
        }

        return $capacity;
    }

    /**
     * Compute storage usage for a launchpad or storage pin.
     *
     * Calculates total volume used based on quantity × volume per unit for each item.
     * Volumes are resolved from: database → ESI → PI commodity map → default (0.01 m³).
     *
     * Standard PI commodity volumes:
     * - P0 (Raw materials): 0.01 m³/unit
     * - P1 (Processed materials): 0.19 m³/unit
     * - P2 (Refined commodities): 1.5 m³/unit
     * - P3 (Specialized commodities): 6.0 m³/unit
     * - P4 (Advanced commodities): 100.0 m³/unit
     */
    public function computeStorageUsage(array $pin, float $capacity): array
    {
        $contents = $pin['contents'] ?? [];
        $totalItems = 0;
        $totalVolume = 0.0;
        $items = [];

        foreach ($contents as $item) {
            $typeId = $item['type_id'] ?? 0;
            $amount = $item['amount'] ?? 0;
            $name = $this->resolveTypeName($typeId);
            $unitVolume = $this->resolveTypeVolume($typeId);
            $volume = $amount * $unitVolume;

            $totalItems += $amount;
            $totalVolume += $volume;
            $items[] = [
                'type_id' => $typeId,
                'name' => $name,
                'amount' => $amount,
                'volume' => round($volume, 2),
            ];
        }

        $usagePercent = $capacity > 0 ? min(100, round(($totalVolume / $capacity) * 100, 1)) : 0;

        return [
            'capacity' => $capacity,
            'used_volume' => round($totalVolume, 2),
            'usage_percent' => $usagePercent,
            'total_items' => $totalItems,
            'items' => $items,
        ];
    }

    /**
     * Resolve the volume (m³) for a single unit of the given type.
     *
     * Checks the local EveType database first, then falls back to ESI,
     * then checks the PI commodity volume mapping, and finally defaults to 0.01 m³.
     */
    protected function resolveTypeVolume(int $typeId): float
    {
        if ($typeId <= 0) {
            return self::DEFAULT_VOLUME;
        }

        // 1. Check local database
        try {
            $localType = EveType::find($typeId);
            if ($localType && $localType->volume !== null) {
                return (float) $localType->volume;
            }
        } catch (\Exception $e) {
            // Table may not exist yet; fall through to ESI
        }

        // 2. Fall back to ESI type info
        $typeInfo = $this->esi->getTypeInfo($typeId);
        if ($typeInfo && isset($typeInfo['volume'])) {
            return (float) $typeInfo['volume'];
        }

        // 3. Check known PI commodity volumes
        if (isset(self::PI_COMMODITY_VOLUMES[$typeId])) {
            return self::PI_COMMODITY_VOLUMES[$typeId];
        }

        return self::DEFAULT_VOLUME;
    }

    /**
     * Resolve a type ID to its name using the ESI service.
     */
    protected function resolveTypeName(int $typeId): string
    {
        if ($typeId <= 0) {
            return 'Unknown';
        }

        $typeInfo = $this->esi->getTypeInfo($typeId);

        return $typeInfo['name'] ?? "Type {$typeId}";
    }

    /**
     * Calculate per-cycle extraction values using the official EVE PI formula.
     *
     * Accounts for extraction decay over time and sinusoidal noise.
     *
     * @see https://developers.eveonline.com/docs/guides/pi/
     *
     * @param  int  $qtyPerCycle  Base quantity per cycle from ESI
     * @param  int  $cycleTime  Cycle time in seconds
     * @param  int  $totalCycles  Number of extraction cycles
     * @return array<int, float> Per-cycle extraction amounts
     */
    public function calculateExtractorCycleValues(int $qtyPerCycle, int $cycleTime, int $totalCycles): array
    {
        if ($qtyPerCycle <= 0 || $cycleTime <= 0 || $totalCycles <= 0) {
            return [];
        }

        $barWidth = (float) $cycleTime / 900.0;
        $values = [];

        for ($i = 0; $i < $totalCycles; $i++) {
            $t = ($i + 0.5) * $barWidth;
            $decayValue = $qtyPerCycle / (1 + $t * self::DECAY_FACTOR);
            $phaseShift = pow($qtyPerCycle, 0.7);

            $sinA = cos($phaseShift + $t * (1.0 / 12.0));
            $sinB = cos($phaseShift / 2.0 + $t * 0.2);
            $sinC = cos($t * 0.5);

            $sinStuff = max(($sinA + $sinB + $sinC) / 3.0, 0);

            $barHeight = $decayValue * (1 + self::NOISE_FACTOR * $sinStuff);
            $values[] = floor($barWidth * $barHeight);
        }

        return $values;
    }

    /**
     * Calculate total extraction output and average rate for an extractor program.
     *
     * @param  int  $qtyPerCycle  Base quantity per cycle from ESI
     * @param  int  $cycleTime  Cycle time in seconds
     * @param  int  $duration  Total program duration in seconds
     * @return array{total_output: float, avg_per_hour: float, total_cycles: int}
     */
    public function calculateExtractorTotals(int $qtyPerCycle, int $cycleTime, int $duration): array
    {
        if ($qtyPerCycle <= 0 || $cycleTime <= 0 || $duration <= 0) {
            return ['total_output' => 0, 'avg_per_hour' => 0.0, 'total_cycles' => 0];
        }

        $totalCycles = intdiv($duration, $cycleTime);
        $cycleValues = $this->calculateExtractorCycleValues($qtyPerCycle, $cycleTime, $totalCycles);
        $totalOutput = array_sum($cycleValues);
        $durationHours = $duration / 3600.0;
        $avgPerHour = $durationHours > 0 ? $totalOutput / $durationHours : 0;

        return [
            'total_output' => $totalOutput,
            'avg_per_hour' => round($avgPerHour, 2),
            'total_cycles' => $totalCycles,
        ];
    }

    /**
     * Clear PI caches for all characters of a user.
     */
    public function clearUserCache(int $userId): void
    {
        $characters = Character::where('user_id', $userId)->get();

        foreach ($characters as $character) {
            Cache::forget("planetary.character.{$character->character_id}");
            $this->esi->clearPlanetaryCache($character->character_id);
        }
    }
}
