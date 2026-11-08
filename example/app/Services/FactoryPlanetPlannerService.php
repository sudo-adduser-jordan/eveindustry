<?php

namespace App\Services;

use App\Models\MarketPrice;
use Illuminate\Support\Facades\Log;

/**
 * Factory Planet Planner Service for EVE Online Planetary Interaction.
 *
 * Calculates the most profitable factory planet configurations by comparing
 * the cost of buying P1 or P2 inputs from the market against the sell value
 * of the produced P2, P3, or P4 output commodities.
 *
 * A "factory planet" uses only factories (no extractors). It buys input
 * materials from the market and produces higher-tier PI commodities to sell.
 *
 * Factory planet types by tier:
 *  - P1→P2: Advanced Industry Facility (60 min cycle, 40+40 P1 → 5 P2)
 *  - P2→P3: High-Tech Production Plant (90 min cycle, 10+10 (or 10+10+10) P2 → 3 P3)
 *  - P3→P4: High-Tech Production Plant (5 hr cycle, various P3 (and some P1) → 1 P4)
 */
class FactoryPlanetPlannerService
{
    /** Jita / The Forge region for market price lookups */
    public const DEFAULT_REGION_ID = 10000002;

    /**
     * Schematic cycle times in seconds.
     */
    public const CYCLE_P1_TO_P2 = 3600;   // 60 min
    public const CYCLE_P2_TO_P3 = 5400;   // 90 min
    public const CYCLE_P3_TO_P4 = 18000;  // 5 hr
    public const CYCLE_P0_TO_P1 = 1800;   // 30 min

    /**
     * Number of factory slots available on a dedicated factory planet.
     * High-tech planets (Barren/Temperate) can fit ~18 HT factories.
     */
    public const FACTORY_SLOTS = 18;

    /**
     * Seconds per day for converting cycle profit to daily profit.
     */
    public const SECONDS_PER_DAY = 86400;

    /**
     * Tier labels for display.
     */
    public const TIER_LABELS = [
        'p1_to_p2' => 'P1 → P2 (Buy P1 materials)',
        'p2_to_p3' => 'P2 → P3 (Buy P2 materials)',
        'p3_to_p4' => 'P3 → P4 (Buy P3 materials)',
        'p1_to_p3' => 'P1 → P3 (Single Planet, Buy P1)',
        'p0_to_p3' => 'P0 → P3 (Single Planet, Buy P0)',
    ];

    /**
     * Optimal P3 factory count for a P1→P3 single-planet setup (18 slots).
     * 2-input P3: 6 P3 + 6 P2_A + 6 P2_B = 18 → 216 P3/day.
     * 3-input P3: 3 P3 + 5 P2_A + 5 P2_B + 5 P2_C = 18 → 144 P3/day.
     */
    public const P1_TO_P3_P3_FACTORIES     = 6;   // 2-input P3
    public const P1_TO_P3_P2A_FACTORIES    = 6;   // 2-input P3
    public const P1_TO_P3_P2B_FACTORIES    = 6;   // 2-input P3
    public const P1_TO_P3_3IN_P3_FACTORIES = 3;   // 3-input P3
    public const P1_TO_P3_3IN_P2_FACTORIES = 5;   // 3-input P3, per P2 type

    /**
     * Optimal factory counts for a P0→P3 single-planet setup (18 slots).
     * 2-input: 1 HTPP + 3 AIF-A + 3 AIF-B + 11 BIF = 18.
     * 3-input: 1 HTPP + 2 AIF-A + 2 AIF-B + 2 AIF-C + 11 BIF = 18.
     * BIF are distributed among distinct P1 types dynamically per product.
     */
    public const P0_TO_P3_P3_FACTORIES      = 1;
    public const P0_TO_P3_P2A_FACTORIES     = 3;   // 2-input P3
    public const P0_TO_P3_P2B_FACTORIES     = 3;   // 2-input P3
    public const P0_TO_P3_3IN_P2_FACTORIES  = 2;   // 3-input P3, per P2 type
    public const P0_TO_P3_BIF_TOTAL         = 11;

    /**
     * P1 → P2 schematics.
     * Advanced Industry Facility: 40+40 P1 → 5 P2, 60 min cycle.
     *
     * Each schematic: ['output_type_id', 'output_qty', 'inputs' => [['type_id', 'qty']], 'cycle_time']
     *
     * @see https://wiki.eveuniversity.org/Planetary_industry
     */
    public const SCHEMATICS_P1_TO_P2 = [
        // type_id  => ['name', output_qty, cycle_time, inputs => [[type_id, qty], ...]]
        2329  => ['name' => 'Biocells',                      'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2396, 40], [2399, 40]]],
        3828  => ['name' => 'Construction Blocks',           'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2398, 40], [2400, 40]]],
        9836  => ['name' => 'Consumer Electronics',          'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2401, 40], [2400, 40]]],
        9832  => ['name' => 'Coolant',                       'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2390, 40], [3645, 40]]],
        44    => ['name' => 'Enriched Uranium',              'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2399, 40], [2400, 40]]],
        3693  => ['name' => 'Fertilizer',                    'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2393, 40], [2395, 40]]],
        15317 => ['name' => 'Genetically Enhanced Livestock','output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2395, 40], [3779, 40]]],
        3725  => ['name' => 'Livestock',                     'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2396, 40], [2395, 40]]],
        3689  => ['name' => 'Mechanical Parts',              'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2398, 40], [2399, 40]]],
        2327  => ['name' => 'Microfiber Shielding',          'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2397, 40], [9828, 40]]],
        9842  => ['name' => 'Miniature Electronics',         'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2401, 40], [9828, 40]]],
        2463  => ['name' => 'Nanites',                       'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2393, 40], [2398, 40]]],
        2317  => ['name' => 'Oxides',                        'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2392, 40], [3683, 40]]],
        2321  => ['name' => 'Polyaramids',                   'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2397, 40], [2392, 40]]],
        3695  => ['name' => 'Polytextiles',                  'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2396, 40], [2397, 40]]],
        9830  => ['name' => 'Rocket Fuel',                   'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2389, 40], [2390, 40]]],
        3697  => ['name' => 'Silicate Glass',                'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2392, 40], [9828, 40]]],
        2312  => ['name' => 'Supertensile Plastics',         'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[3683, 40], [3779, 40]]],
        9838  => ['name' => 'Superconductors',               'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2389, 40], [3645, 40]]],
        3691  => ['name' => 'Synthetic Oil',                 'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2390, 40], [3683, 40]]],
        2319  => ['name' => 'Test Cultures',                 'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2393, 40], [3645, 40]]],
        9840  => ['name' => 'Transmitter',                   'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2389, 40], [2401, 40]]],
        3775  => ['name' => 'Viral Agent',                   'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2393, 40], [3779, 40]]],
        2328  => ['name' => 'Water-Cooled CPU',              'output_qty' => 5,  'cycle_time' => self::CYCLE_P1_TO_P2, 'inputs' => [[2398, 40], [3645, 40]]],
    ];

    /**
     * P2 → P3 schematics.
     * High-Tech Production Plant: 10+10 (or 10+10+10) P2 → 3 P3, 90 min cycle.
     */
    public const SCHEMATICS_P2_TO_P3 = [
        2358  => ['name' => 'Biotech Research Reports',     'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[2463, 10], [3725, 10], [3828, 10]]],
        2345  => ['name' => 'Camera Drones',                'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[3697, 10], [9830, 10]]],
        2344  => ['name' => 'Condensates',                  'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[2317, 10], [9832, 10]]],
        2367  => ['name' => 'Cryoprotectant Solution',      'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[2319, 10], [3691, 10], [3693, 10]]],
        17392 => ['name' => 'Data Chips',                   'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[2312, 10], [2327, 10]]],
        2348  => ['name' => 'Gel-Matrix Biopaste',          'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[2317, 10], [2329, 10], [9838, 10]]],
        9834  => ['name' => 'Guidance Systems',             'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[2328, 10], [9840, 10]]],
        2366  => ['name' => 'Hazmat Detection Systems',     'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[3695, 10], [3775, 10], [9840, 10]]],
        2361  => ['name' => 'Hermetic Membranes',           'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[2321, 10], [15317, 10]]],
        17898 => ['name' => 'High-Tech Transmitters',       'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[2321, 10], [9840, 10]]],
        2360  => ['name' => 'Industrial Explosives',        'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[3693, 10], [3695, 10]]],
        2354  => ['name' => 'Neocoms',                      'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[2329, 10], [3697, 10]]],
        2352  => ['name' => 'Nuclear Reactors',             'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[44, 10], [2327, 10]]],
        9846  => ['name' => 'Planetary Vehicles',           'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[2312, 10], [3689, 10], [9842, 10]]],
        9848  => ['name' => 'Robotics',                     'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[3689, 10], [9836, 10]]],
        2351  => ['name' => 'Smartfab Units',               'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[3828, 10], [9842, 10]]],
        2349  => ['name' => 'Supercomputers',               'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[2328, 10], [9832, 10], [9836, 10]]],
        2346  => ['name' => 'Synthetic Synapses',           'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[2312, 10], [2319, 10]]],
        12836 => ['name' => 'Transcranial Microcontrollers','output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[2329, 10], [2463, 10]]],
        17136 => ['name' => 'Ukomi Superconductors',        'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[3691, 10], [9838, 10]]],
        28974 => ['name' => 'Vaccines',                     'output_qty' => 3,  'cycle_time' => self::CYCLE_P2_TO_P3, 'inputs' => [[3725, 10], [3775, 10]]],
    ];

    /**
     * P3 → P4 schematics.
     * High-Tech Production Plant: various P3 (and in some cases P1) inputs → 1 P4, 5 hr cycle.
     */
    public const SCHEMATICS_P3_TO_P4 = [
        2867 => ['name' => 'Broadcast Node',               'output_qty' => 1, 'cycle_time' => self::CYCLE_P3_TO_P4, 'inputs' => [[2354, 6], [17392, 6], [17898, 6]]],
        2868 => ['name' => 'Integrity Response Drones',    'output_qty' => 1, 'cycle_time' => self::CYCLE_P3_TO_P4, 'inputs' => [[2348, 6], [2366, 6], [9846, 6]]],
        2869 => ['name' => 'Nano-Factory',                 'output_qty' => 1, 'cycle_time' => self::CYCLE_P3_TO_P4, 'inputs' => [[2360, 6], [2398, 40], [17136, 6]]],
        2870 => ['name' => 'Organic Mortar Applicators',   'output_qty' => 1, 'cycle_time' => self::CYCLE_P3_TO_P4, 'inputs' => [[2344, 6], [2393, 40], [9848, 6]]],
        2871 => ['name' => 'Recursive Computing Module',   'output_qty' => 1, 'cycle_time' => self::CYCLE_P3_TO_P4, 'inputs' => [[2346, 6], [9834, 6], [12836, 6]]],
        2872 => ['name' => 'Self-Harmonizing Power Core',  'output_qty' => 1, 'cycle_time' => self::CYCLE_P3_TO_P4, 'inputs' => [[2345, 6], [2352, 6], [2361, 6]]],
        2875 => ['name' => 'Sterile Conduits',             'output_qty' => 1, 'cycle_time' => self::CYCLE_P3_TO_P4, 'inputs' => [[2351, 6], [3645, 40], [28974, 6]]],
        2876 => ['name' => 'Wetware Mainframe',            'output_qty' => 1, 'cycle_time' => self::CYCLE_P3_TO_P4, 'inputs' => [[2349, 6], [2358, 6], [2367, 6]]],
    ];

    /**
     * P0 → P1 schematics.
     * Basic Industry Facility: 3000 P0 → 20 P1, 30 min cycle.
     * Keyed by P1 output type ID.
     */
    public const SCHEMATICS_P0_TO_P1 = [
        2389 => ['name' => 'Plasmoids',          'output_qty' => 20, 'cycle_time' => self::CYCLE_P0_TO_P1, 'inputs' => [[2308, 3000]]],
        2390 => ['name' => 'Electrolytes',       'output_qty' => 20, 'cycle_time' => self::CYCLE_P0_TO_P1, 'inputs' => [[2309, 3000]]],
        2392 => ['name' => 'Oxidizing Compound', 'output_qty' => 20, 'cycle_time' => self::CYCLE_P0_TO_P1, 'inputs' => [[2311, 3000]]],
        2393 => ['name' => 'Bacteria',           'output_qty' => 20, 'cycle_time' => self::CYCLE_P0_TO_P1, 'inputs' => [[2073, 3000]]],
        2395 => ['name' => 'Proteins',           'output_qty' => 20, 'cycle_time' => self::CYCLE_P0_TO_P1, 'inputs' => [[2287, 3000]]],
        2396 => ['name' => 'Biofuels',           'output_qty' => 20, 'cycle_time' => self::CYCLE_P0_TO_P1, 'inputs' => [[2288, 3000]]],
        2397 => ['name' => 'Industrial Fibers',  'output_qty' => 20, 'cycle_time' => self::CYCLE_P0_TO_P1, 'inputs' => [[2305, 3000]]],
        2398 => ['name' => 'Reactive Metals',    'output_qty' => 20, 'cycle_time' => self::CYCLE_P0_TO_P1, 'inputs' => [[2267, 3000]]],
        2399 => ['name' => 'Precious Metals',    'output_qty' => 20, 'cycle_time' => self::CYCLE_P0_TO_P1, 'inputs' => [[2270, 3000]]],
        2400 => ['name' => 'Toxic Metals',       'output_qty' => 20, 'cycle_time' => self::CYCLE_P0_TO_P1, 'inputs' => [[2272, 3000]]],
        2401 => ['name' => 'Chiral Structures',  'output_qty' => 20, 'cycle_time' => self::CYCLE_P0_TO_P1, 'inputs' => [[2306, 3000]]],
        3645 => ['name' => 'Water',              'output_qty' => 20, 'cycle_time' => self::CYCLE_P0_TO_P1, 'inputs' => [[2268, 3000]]],
        3683 => ['name' => 'Oxygen',             'output_qty' => 20, 'cycle_time' => self::CYCLE_P0_TO_P1, 'inputs' => [[2310, 3000]]],
        3779 => ['name' => 'Biomass',            'output_qty' => 20, 'cycle_time' => self::CYCLE_P0_TO_P1, 'inputs' => [[2286, 3000]]],
        9828 => ['name' => 'Silicon',            'output_qty' => 20, 'cycle_time' => self::CYCLE_P0_TO_P1, 'inputs' => [[2307, 3000]]],
    ];

    /**
     * Names for P0 raw materials (for display).
     */
    public const P0_NAMES = [
        2073 => 'Microorganisms',
        2267 => 'Base Metals',
        2268 => 'Aqueous Liquids',
        2270 => 'Noble Metals',
        2272 => 'Heavy Metals',
        2286 => 'Planktic Colonies',
        2287 => 'Complex Organisms',
        2288 => 'Carbon Compounds',
        2305 => 'Autotrophs',
        2306 => 'Non-CS Crystals',
        2307 => 'Felsic Magma',
        2308 => 'Suspended Plasma',
        2309 => 'Ionic Solutions',
        2310 => 'Noble Gas',
        2311 => 'Reactive Gas',
    ];

    /**
     * Names for P1 materials (for display).
     */
    public const P1_NAMES = [
        2389 => 'Plasmoids',
        2390 => 'Electrolytes',
        2392 => 'Oxidizing Compound',
        2393 => 'Bacteria',
        2395 => 'Proteins',
        2396 => 'Biofuels',
        2397 => 'Industrial Fibers',
        2398 => 'Reactive Metals',
        2399 => 'Precious Metals',
        2400 => 'Toxic Metals',
        2401 => 'Chiral Structures',
        3645 => 'Water',
        3683 => 'Oxygen',
        3779 => 'Biomass',
        9828 => 'Silicon',
    ];

    /**
     * Names for P2 materials (for display).
     */
    public const P2_NAMES = [
        44    => 'Enriched Uranium',
        2312  => 'Supertensile Plastics',
        2317  => 'Oxides',
        2319  => 'Test Cultures',
        2321  => 'Polyaramids',
        2327  => 'Microfiber Shielding',
        2328  => 'Water-Cooled CPU',
        2329  => 'Biocells',
        2463  => 'Nanites',
        3689  => 'Mechanical Parts',
        3691  => 'Synthetic Oil',
        3693  => 'Fertilizer',
        3695  => 'Polytextiles',
        3697  => 'Silicate Glass',
        3725  => 'Livestock',
        3775  => 'Viral Agent',
        3828  => 'Construction Blocks',
        9830  => 'Rocket Fuel',
        9832  => 'Coolant',
        9836  => 'Consumer Electronics',
        9838  => 'Superconductors',
        9840  => 'Transmitter',
        9842  => 'Miniature Electronics',
        15317 => 'Genetically Enhanced Livestock',
    ];

    public function __construct(protected ?EsiService $esi = null) {}

    /**
     * Per-instance memoized ESI average price map, so repeated calls within a
     * single request/job don't refetch and rebuild the (large) map each time.
     *
     * @var array<int, float>|null
     */
    private ?array $esiAveragePriceMap = null;

    /**
     * Load ESI global average prices keyed by type_id.
     * Called lazily and cached for the lifetime of the request/job.
     *
     * @return array<int, float>  type_id → average_price
     */
    protected function loadEsiAveragePrices(): array
    {
        if ($this->esiAveragePriceMap !== null) {
            return $this->esiAveragePriceMap;
        }

        if ($this->esi === null) {
            return $this->esiAveragePriceMap = [];
        }

        try {
            $raw = $this->esi->getMarketPrices();
        } catch (\Exception $e) {
            Log::warning('FactoryPlanetPlannerService: failed to fetch ESI market prices, falling back to database prices only: ' . $e->getMessage());
            return [];
        }

        if (! is_array($raw)) {
            return $this->esiAveragePriceMap = [];
        }

        $map = [];
        foreach ($raw as $entry) {
            $typeId = $entry['type_id'] ?? null;
            $avg    = $entry['average_price'] ?? null;
            if ($typeId !== null && $avg > 0) {
                $map[(int) $typeId] = (float) $avg;
            }
        }

        return $this->esiAveragePriceMap = $map;
    }

    /**
     * Calculate profitability for all schematics of a given tier.
     *
     * @param  string  $tier      'p1_to_p2' | 'p2_to_p3' | 'p3_to_p4' | 'p1_to_p3'
     * @param  int     $regionId  Market region ID (default: The Forge/Jita)
     * @return array   Ranked array of schematic profitability data
     */
    public function calculateProfitability(string $tier, int $regionId = self::DEFAULT_REGION_ID): array
    {
        if ($tier === 'p1_to_p3') {
            return $this->calculateP1toP3Profitability($regionId);
        }

        if ($tier === 'p0_to_p3') {
            return $this->calculateP0toP3Profitability($regionId);
        }

        $schematics = match ($tier) {
            'p1_to_p2' => self::SCHEMATICS_P1_TO_P2,
            'p2_to_p3' => self::SCHEMATICS_P2_TO_P3,
            'p3_to_p4' => self::SCHEMATICS_P3_TO_P4,
            default    => [],
        };

        // Load ESI average prices once for all schematics in this tier
        $esiPrices = $this->loadEsiAveragePrices();

        $results = [];

        foreach ($schematics as $outputTypeId => $schematic) {
            $result = $this->calculateSchematicProfit(
                $outputTypeId,
                $schematic,
                $regionId,
                $esiPrices
            );
            if ($result !== null) {
                $results[] = $result;
            }
        }

        // Sort by daily profit descending
        usort($results, fn ($a, $b) => $b['daily_profit_per_factory'] <=> $a['daily_profit_per_factory']);

        return $results;
    }

    /**
     * Calculate profitability for a single schematic.
     *
     * Returns an array with:
     *  - output_type_id, output_name, output_qty
     *  - inputs: array of input type/name/qty/unit_cost/total_cost/price_source
     *  - total_input_cost: total ISK cost of all inputs for one cycle
     *  - output_sell_value: ISK from selling output at sell_min
     *  - output_buy_value: ISK from selling output at buy_max
     *  - profit_per_cycle_sell, profit_per_cycle_buy
     *  - margin_percent_sell, margin_percent_buy
     *  - cycles_per_day: number of cycles per day
     *  - daily_profit_per_factory: profit per day per factory slot (using sell prices)
     *  - prices_available: bool (false if any prices are missing)
     *
     * @param  array<int, float>  $esiPrices  Pre-loaded ESI average prices keyed by type_id
     */
    public function calculateSchematicProfit(int $outputTypeId, array $schematic, int $regionId, array $esiPrices = []): ?array
    {
        $inputTypeIds = array_column($schematic['inputs'], 0);
        $allTypeIds = array_merge($inputTypeIds, [$outputTypeId]);

        // Fetch all prices in one query
        $prices = MarketPrice::whereIn('type_id', $allTypeIds)
            ->where('region_id', $regionId)
            ->get()
            ->keyBy('type_id');

        $totalInputCost = 0.0;
        $inputDetails = [];
        $pricesAvailable = true;

        foreach ($schematic['inputs'] as [$typeId, $qty]) {
            $price = $prices->get($typeId);
            $unitCost = null;
            $priceSource = null;

            if ($price && $price->buy_max > 0) {
                $unitCost = (float) $price->buy_max;
                $priceSource = 'buy_max';
            } elseif ($price && $price->average_price > 0) {
                $unitCost = (float) $price->average_price;
                $priceSource = 'average_price';
            } elseif (isset($esiPrices[$typeId])) {
                $unitCost = $esiPrices[$typeId];
                $priceSource = 'esi_average';
            } else {
                $pricesAvailable = false;
                $unitCost = 0.0;
                $priceSource = null;
            }

            $inputCost = $unitCost * $qty;
            $totalInputCost += $inputCost;

            $inputDetails[] = [
                'type_id'      => $typeId,
                'name'         => $this->resolveInputName($typeId),
                'qty'          => $qty,
                'unit_cost'    => $unitCost,
                'total_cost'   => $inputCost,
                'has_price'    => $priceSource !== null,
                'price_source' => $priceSource,
            ];
        }

        // Output price
        $outputPrice = $prices->get($outputTypeId);
        $outputSellMin = null;
        $outputBuyMax = null;
        $outputPriceSource = null;

        if ($outputPrice) {
            $outputSellMin = ($outputPrice->sell_min > 0) ? (float) $outputPrice->sell_min : null;
            $outputBuyMax  = ($outputPrice->buy_max > 0) ? (float) $outputPrice->buy_max : null;
            if ($outputSellMin !== null || $outputBuyMax !== null) {
                $outputPriceSource = $outputSellMin !== null ? 'sell_min' : 'buy_max';
            } elseif ($outputPrice->average_price > 0) {
                $outputSellMin = (float) $outputPrice->average_price;
                $outputPriceSource = 'average_price';
            }
        }

        // Fall back to ESI average price for the output if no DB price is available
        if ($outputSellMin === null && $outputBuyMax === null && isset($esiPrices[$outputTypeId])) {
            $outputSellMin = $esiPrices[$outputTypeId];
            $outputPriceSource = 'esi_average';
        }

        if (! $outputSellMin && ! $outputBuyMax) {
            $pricesAvailable = false;
        }

        $outputQty = (int) $schematic['output_qty'];
        $cycleTime = (int) $schematic['cycle_time'];

        $sellValue   = ($outputSellMin ?? 0) * $outputQty;
        $buyValue    = ($outputBuyMax ?? 0) * $outputQty;

        $profitSell  = $sellValue - $totalInputCost;
        $profitBuy   = $buyValue - $totalInputCost;

        $marginSell  = $totalInputCost > 0 ? round($profitSell / $totalInputCost * 100, 2) : null;
        $marginBuy   = $totalInputCost > 0 ? round($profitBuy / $totalInputCost * 100, 2) : null;

        $cyclesPerDay             = $cycleTime > 0 ? self::SECONDS_PER_DAY / $cycleTime : 0;
        $dailyProfitPerFactory    = $profitSell * $cyclesPerDay;
        $dailyProfitBuyPerFactory = $profitBuy * $cyclesPerDay;

        // Planet-level figures: multiply per-factory values by factory slot count (18)
        $dailyInputCostPlanet     = $totalInputCost * $cyclesPerDay * self::FACTORY_SLOTS;
        $dailyOutputSellPlanet    = $sellValue * $cyclesPerDay * self::FACTORY_SLOTS;
        $dailyOutputBuyPlanet     = $buyValue * $cyclesPerDay * self::FACTORY_SLOTS;
        $dailyProfitSellPlanet    = $dailyProfitPerFactory * self::FACTORY_SLOTS;
        $dailyProfitBuyPlanet     = $dailyProfitBuyPerFactory * self::FACTORY_SLOTS;

        return [
            'output_type_id'               => $outputTypeId,
            'output_name'                  => $schematic['name'],
            'output_qty'                   => $outputQty,
            'cycle_time'                   => $cycleTime,
            'inputs'                       => $inputDetails,
            'total_input_cost'             => $totalInputCost,
            'output_sell_min'              => $outputSellMin,
            'output_buy_max'               => $outputBuyMax,
            'output_price_source'          => $outputPriceSource,
            'output_sell_value'            => $sellValue,
            'output_buy_value'             => $buyValue,
            'profit_per_cycle_sell'        => $profitSell,
            'profit_per_cycle_buy'         => $profitBuy,
            'margin_percent_sell'          => $marginSell,
            'margin_percent_buy'           => $marginBuy,
            'cycles_per_day'               => $cyclesPerDay,
            'daily_profit_per_factory'     => $dailyProfitPerFactory,
            'daily_profit_buy_per_factory' => $dailyProfitBuyPerFactory,
            // Planet-level (×18 factories)
            'factory_slots'                => self::FACTORY_SLOTS,
            'daily_input_cost_planet'      => $dailyInputCostPlanet,
            'daily_output_sell_planet'     => $dailyOutputSellPlanet,
            'daily_output_buy_planet'      => $dailyOutputBuyPlanet,
            'daily_profit_sell_planet'     => $dailyProfitSellPlanet,
            'daily_profit_buy_planet'      => $dailyProfitBuyPlanet,
            'prices_available'             => $pricesAvailable,
        ];
    }

    /**
     * Add PI customs tax and after-tax profit to each result row.
     *
     * Tax is charged on the market value of what is imported to and exported
     * from the planet: tax_rate% of each. Standard tiers use the per-planet
     * input cost and output sell value; the P1/P0 → P3 chains use the daily
     * import and export values.
     *
     * Adds daily_import_tax, daily_export_tax, daily_tax_total and
     * daily_profit_after_tax. With a 0% rate, after-tax profit equals the
     * pre-tax daily profit.
     *
     * @param  float  $taxRate  Customs tax percentage (0-100)
     */
    public function applyCustomsTax(array $results, string $tier, float $taxRate): array
    {
        $chain = in_array($tier, ['p1_to_p3', 'p0_to_p3'], true);
        [$importKey, $exportKey, $profitKey] = $chain
            ? ['daily_import_value', 'daily_export_value', 'daily_profit_sell']
            : ['daily_input_cost_planet', 'daily_output_sell_planet', 'daily_profit_sell_planet'];

        return array_map(function (array $row) use ($importKey, $exportKey, $profitKey, $taxRate) {
            $importTax = (float) ($row[$importKey] ?? 0) * $taxRate / 100;
            $exportTax = (float) ($row[$exportKey] ?? 0) * $taxRate / 100;
            $totalTax = $importTax + $exportTax;

            return array_merge($row, [
                'daily_import_tax' => $importTax,
                'daily_export_tax' => $exportTax,
                'daily_tax_total' => $totalTax,
                'daily_profit_after_tax' => (float) ($row[$profitKey] ?? 0) - $totalTax,
            ]);
        }, $results);
    }

    /**
     * Resolve the display name for an input material (P1, P2, or P3).
     */
    public function resolveInputName(int $typeId): string
    {
        return self::P0_NAMES[$typeId]
            ?? self::P1_NAMES[$typeId]
            ?? self::P2_NAMES[$typeId]
            ?? self::SCHEMATICS_P2_TO_P3[$typeId]['name']
            ?? self::SCHEMATICS_P3_TO_P4[$typeId]['name']
            ?? "Type {$typeId}";
    }

    /**
     * Return all unique input type IDs for a given tier's schematics.
     *
     * @param  string  $tier  'p1_to_p2' | 'p2_to_p3' | 'p3_to_p4'
     * @return array<int>
     */
    public function getInputTypeIds(string $tier): array
    {
        $schematics = match ($tier) {
            'p1_to_p2' => self::SCHEMATICS_P1_TO_P2,
            'p2_to_p3' => self::SCHEMATICS_P2_TO_P3,
            'p3_to_p4' => self::SCHEMATICS_P3_TO_P4,
            default    => [],
        };

        $ids = [];
        foreach ($schematics as $schematic) {
            foreach ($schematic['inputs'] as [$typeId]) {
                $ids[] = $typeId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Return all unique output type IDs for a given tier's schematics.
     *
     * @param  string  $tier  'p1_to_p2' | 'p2_to_p3' | 'p3_to_p4'
     * @return array<int>
     */
    public function getOutputTypeIds(string $tier): array
    {
        $schematics = match ($tier) {
            'p1_to_p2' => self::SCHEMATICS_P1_TO_P2,
            'p2_to_p3' => self::SCHEMATICS_P2_TO_P3,
            'p3_to_p4' => self::SCHEMATICS_P3_TO_P4,
            default    => [],
        };

        return array_keys($schematics);
    }

    /**
     * Calculate profitability for all P3 products using a single-planet P1→P3 setup.
     *
     * Factory configuration (18 slots):
     *   - 3  High-Tech Production Plants  (P3 factories)
     *   - 8  Advanced Industry Facilities (P2 type A)
     *   - 7  Advanced Industry Facilities (P2 type B)
     *
     * The P2-type with only 7 factories is the production bottleneck and limits
     * effective daily P3 output to 126 units per P3 product.
     *
     * @param  int  $regionId  Market region ID
     * @return array Ranked array of P1→P3 plan data sorted by daily profit descending
     */
    public function calculateP1toP3Profitability(int $regionId = self::DEFAULT_REGION_ID): array
    {
        $esiPrices = $this->loadEsiAveragePrices();
        $results = [];

        foreach (self::SCHEMATICS_P2_TO_P3 as $p3TypeId => $p3Schematic) {
            $result = $this->calculateP1toP3Plan($p3TypeId, $p3Schematic, $regionId, $esiPrices);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        // Sort by daily profit (sell) descending
        usort($results, fn ($a, $b) => $b['daily_profit_sell'] <=> $a['daily_profit_sell']);

        return $results;
    }

    /**
     * Calculate a single P1→P3 factory-planet plan for one P3 product.
     *
     * Returns daily-level figures (all scaled to a full planet day) including:
     *  - factory_split: p3/p2_a/p2_b factory counts
     *  - p2_inputs: intermediate P2 production details
     *  - p1_inputs: raw P1 material requirements per day
     *  - daily cost/revenue/profit figures
     *  - daily_import_value / daily_export_value (for tax calculations)
     *
     * @param  int    $p3TypeId     Output P3 type ID
     * @param  array  $p3Schematic  Entry from SCHEMATICS_P2_TO_P3
     * @param  int    $regionId     Market region ID
     * @param  array  $esiPrices    Pre-loaded ESI average prices keyed by type_id
     * @return array|null  null if the required P1→P2 schematics are missing
     */
    public function calculateP1toP3Plan(int $p3TypeId, array $p3Schematic, int $regionId, array $esiPrices = []): ?array
    {
        $p2Inputs   = $p3Schematic['inputs'];
        $inputCount = count($p2Inputs);

        // Determine factory split based on input count (2 or 3 P2 types)
        if ($inputCount === 2) {
            $p3Factories     = self::P1_TO_P3_P3_FACTORIES;
            $p2FactoryCounts = [self::P1_TO_P3_P2A_FACTORIES, self::P1_TO_P3_P2B_FACTORIES];
        } elseif ($inputCount === 3) {
            $p3Factories     = self::P1_TO_P3_3IN_P3_FACTORIES;
            $p2FactoryCounts = array_fill(0, $inputCount, self::P1_TO_P3_3IN_P2_FACTORIES);
        } else {
            return null;
        }

        // Validate all P2 inputs have a P1→P2 schematic
        $p2Schematics = [];
        foreach ($p2Inputs as [$p2TypeId]) {
            $p2Schematic = self::SCHEMATICS_P1_TO_P2[$p2TypeId] ?? null;
            if ($p2Schematic === null) {
                return null;
            }
            $p2Schematics[] = $p2Schematic;
        }

        $p2OutputPerCycle = 5; // all P1→P2 schematics produce 5 P2 per cycle
        $p2CyclesPerDay   = self::SECONDS_PER_DAY / self::CYCLE_P1_TO_P2; // 24
        $p3CyclesPerDay   = self::SECONDS_PER_DAY / self::CYCLE_P2_TO_P3; // 16

        // P2 supply per day for each P2 input group
        $p2SupplyPerDay = [];
        for ($i = 0; $i < $inputCount; $i++) {
            $p2SupplyPerDay[$i] = $p2FactoryCounts[$i] * $p2OutputPerCycle * $p2CyclesPerDay;
        }

        // Max P3 cycles limited by each P2 supply or factory capacity
        $maxCyclesByFactories = $p3Factories * $p3CyclesPerDay;
        $maxCyclesByP2        = [];
        for ($i = 0; $i < $inputCount; $i++) {
            [, $p2Qty] = $p2Inputs[$i];
            $maxCyclesByP2[$i] = $p2SupplyPerDay[$i] / max(1, $p2Qty);
        }
        $effectiveP3Cycles = min($maxCyclesByFactories, ...$maxCyclesByP2);
        $effectiveP3Output = $effectiveP3Cycles * (int) $p3Schematic['output_qty'];

        // P2 consumed/cycles needed per P2 group
        $p2ConsumedPerDay = [];
        $p2CyclesNeeded   = [];
        for ($i = 0; $i < $inputCount; $i++) {
            [, $p2Qty] = $p2Inputs[$i];
            $p2ConsumedPerDay[$i] = $effectiveP3Cycles * $p2Qty;
            $p2CyclesNeeded[$i]   = $p2ConsumedPerDay[$i] / $p2OutputPerCycle;
        }

        // P1 consumed per day: derived from P2 cycles needed for each P2 group
        $p1DailyQtys = []; // type_id => qty
        for ($i = 0; $i < $inputCount; $i++) {
            foreach ($p2Schematics[$i]['inputs'] as [$p1TypeId, $p1Qty]) {
                $p1DailyQtys[$p1TypeId] = ($p1DailyQtys[$p1TypeId] ?? 0) + $p2CyclesNeeded[$i] * $p1Qty;
            }
        }

        // Fetch prices for all P1 inputs and the P3 output in one query
        $allTypeIds = array_merge(array_keys($p1DailyQtys), [$p3TypeId]);
        $prices = MarketPrice::whereIn('type_id', $allTypeIds)
            ->where('region_id', $regionId)
            ->get()
            ->keyBy('type_id');

        // Build P1 input details and total cost
        $pricesAvailable   = true;
        $totalDailyP1Cost  = 0.0;
        $p1InputDetails    = [];

        foreach ($p1DailyQtys as $p1TypeId => $dailyQty) {
            $price  = $prices->get($p1TypeId);
            $unitCost    = null;
            $priceSource = null;

            if ($price && $price->buy_max > 0) {
                $unitCost    = (float) $price->buy_max;
                $priceSource = 'buy_max';
            } elseif ($price && $price->average_price > 0) {
                $unitCost    = (float) $price->average_price;
                $priceSource = 'average_price';
            } elseif (isset($esiPrices[$p1TypeId])) {
                $unitCost    = $esiPrices[$p1TypeId];
                $priceSource = 'esi_average';
            } else {
                $pricesAvailable = false;
                $unitCost    = 0.0;
            }

            $dailyCost         = $unitCost * $dailyQty;
            $totalDailyP1Cost += $dailyCost;

            $p1InputDetails[] = [
                'type_id'      => $p1TypeId,
                'name'         => $this->resolveInputName($p1TypeId),
                'daily_qty'    => $dailyQty,
                'unit_cost'    => $unitCost,
                'daily_cost'   => $dailyCost,
                'has_price'    => $priceSource !== null,
                'price_source' => $priceSource,
            ];
        }

        // P3 output price
        $p3PriceRow  = $prices->get($p3TypeId);
        $p3SellMin   = null;
        $p3BuyMax    = null;
        $p3PriceSource = null;

        if ($p3PriceRow) {
            $p3SellMin = ($p3PriceRow->sell_min > 0) ? (float) $p3PriceRow->sell_min : null;
            $p3BuyMax  = ($p3PriceRow->buy_max  > 0) ? (float) $p3PriceRow->buy_max  : null;
            if ($p3SellMin !== null || $p3BuyMax !== null) {
                $p3PriceSource = $p3SellMin !== null ? 'sell_min' : 'buy_max';
            } elseif ($p3PriceRow->average_price > 0) {
                $p3SellMin     = (float) $p3PriceRow->average_price;
                $p3PriceSource = 'average_price';
            }
        }

        if ($p3SellMin === null && $p3BuyMax === null && isset($esiPrices[$p3TypeId])) {
            $p3SellMin     = $esiPrices[$p3TypeId];
            $p3PriceSource = 'esi_average';
        }

        if (! $p3SellMin && ! $p3BuyMax) {
            $pricesAvailable = false;
        }

        $dailyP3SellValue = ($p3SellMin ?? 0.0) * $effectiveP3Output;
        $dailyP3BuyValue  = ($p3BuyMax  ?? 0.0) * $effectiveP3Output;
        $dailyProfitSell  = $dailyP3SellValue - $totalDailyP1Cost;
        $dailyProfitBuy   = $dailyP3BuyValue  - $totalDailyP1Cost;
        $marginSell       = $totalDailyP1Cost > 0
            ? round($dailyProfitSell / $totalDailyP1Cost * 100, 2)
            : null;

        // Build p2_inputs array
        $p2InputDetails = [];
        for ($i = 0; $i < $inputCount; $i++) {
            [$p2TypeId] = $p2Inputs[$i];
            $p2InputDetails[] = [
                'type_id'        => $p2TypeId,
                'name'           => self::P2_NAMES[$p2TypeId] ?? "Type {$p2TypeId}",
                'factories'      => $p2FactoryCounts[$i],
                'daily_produced' => $p2SupplyPerDay[$i],
                'daily_consumed' => $p2ConsumedPerDay[$i],
            ];
        }

        return [
            'output_type_id'       => $p3TypeId,
            'output_name'          => $p3Schematic['name'],
            'output_qty_per_day'   => $effectiveP3Output,
            'factory_slots'        => self::FACTORY_SLOTS,
            'factory_split'        => [
                'p3_factories' => $p3Factories,
                'total'        => self::FACTORY_SLOTS,
            ],
            'p2_inputs'            => $p2InputDetails,
            'p1_inputs'            => $p1InputDetails,
            'total_daily_p1_cost'  => $totalDailyP1Cost,
            'p3_sell_min'          => $p3SellMin,
            'p3_buy_max'           => $p3BuyMax,
            'p3_price_source'      => $p3PriceSource,
            'daily_p3_sell_value'  => $dailyP3SellValue,
            'daily_p3_buy_value'   => $dailyP3BuyValue,
            'daily_profit_sell'    => $dailyProfitSell,
            'daily_profit_buy'     => $dailyProfitBuy,
            'margin_percent_sell'  => $marginSell,
            // Import/export values for client-side tax calculation
            'daily_import_value'   => $totalDailyP1Cost,
            'daily_export_value'   => $dailyP3SellValue,
            'prices_available'     => $pricesAvailable,
        ];
    }

    /**
     * Calculate profitability for all P3 products using a single-planet P0→P3 setup.
     *
     * Factory configuration (18 slots):
     *   - 1  High-Tech Production Plant   (P3 factory)
     *   - 3  Advanced Industry Facilities  (P2 type A)
     *   - 3  Advanced Industry Facilities  (P2 type B)
     *   - 11 Basic Industry Facilities     (P0→P1, distributed dynamically)
     *
     * @param  int  $regionId  Market region ID
     * @return array Ranked array of P0→P3 plan data sorted by daily profit descending
     */
    public function calculateP0toP3Profitability(int $regionId = self::DEFAULT_REGION_ID): array
    {
        $esiPrices = $this->loadEsiAveragePrices();
        $results = [];

        foreach (self::SCHEMATICS_P2_TO_P3 as $p3TypeId => $p3Schematic) {
            $result = $this->calculateP0toP3Plan($p3TypeId, $p3Schematic, $regionId, $esiPrices);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        usort($results, fn ($a, $b) => $b['daily_profit_sell'] <=> $a['daily_profit_sell']);

        return $results;
    }

    /**
     * Calculate a single P0→P3 factory-planet plan for one P3 product.
     *
     * Traces the full chain P0→P1→P2→P3 and calculates:
     *  - BIF (P0→P1) allocation per distinct P1 type
     *  - Bottleneck-limited effective daily P3 output
     *  - Daily P0 cost (using buy_max) and P3 revenue (using sell_min)
     *
     * @param  int    $p3TypeId     Output P3 type ID
     * @param  array  $p3Schematic  Entry from SCHEMATICS_P2_TO_P3
     * @param  int    $regionId     Market region ID
     * @param  array  $esiPrices    Pre-loaded ESI average prices keyed by type_id
     * @return array|null  null if required schematics are missing
     */
    public function calculateP0toP3Plan(int $p3TypeId, array $p3Schematic, int $regionId, array $esiPrices = []): ?array
    {
        // Trace chain: P3 → P2 inputs → P1 inputs → P0 inputs
        $p2Inputs   = $p3Schematic['inputs'];
        $inputCount = count($p2Inputs);

        // Validate all P2 inputs have a P1→P2 schematic
        $p2Schematics = [];
        foreach ($p2Inputs as [$p2TypeId]) {
            $p2Schematic = self::SCHEMATICS_P1_TO_P2[$p2TypeId] ?? null;
            if ($p2Schematic === null) {
                return null;
            }
            $p2Schematics[] = $p2Schematic;
        }

        // Factory counts depend on how many P2 input types the P3 recipe needs
        $p3Factories  = self::P0_TO_P3_P3_FACTORIES;
        $bifTotal     = self::P0_TO_P3_BIF_TOTAL;
        if ($inputCount === 3) {
            $p2FactoryCounts = array_fill(0, $inputCount, self::P0_TO_P3_3IN_P2_FACTORIES);
        } else {
            $p2FactoryCounts = [self::P0_TO_P3_P2A_FACTORIES, self::P0_TO_P3_P2B_FACTORIES];
        }

        // Production rates
        $bifOutputPerDay = 20 * (self::SECONDS_PER_DAY / self::CYCLE_P0_TO_P1);  // 960 P1/day per BIF
        $p1PerAifPerDay  = 40 * (self::SECONDS_PER_DAY / self::CYCLE_P1_TO_P2);  // 960 P1/day consumed per AIF per input type
        $p2OutputPerCycle = 5;
        $p2CyclesPerDay   = self::SECONDS_PER_DAY / self::CYCLE_P1_TO_P2;  // 24
        $p3CyclesPerDay   = self::SECONDS_PER_DAY / self::CYCLE_P2_TO_P3;  // 16
        $p3OutputQty      = (int) $p3Schematic['output_qty'];               // 3

        // Collect distinct P1 types and their demand from each AIF group
        // demand[p1_type_id][$i] = demand from AIF group i
        $p1Demands = [];
        for ($i = 0; $i < $inputCount; $i++) {
            foreach ($p2Schematics[$i]['inputs'] as [$p1TypeId]) {
                for ($j = 0; $j < $inputCount; $j++) {
                    $p1Demands[$p1TypeId][$j] = $p1Demands[$p1TypeId][$j] ?? 0;
                }
                $p1Demands[$p1TypeId][$i] += $p2FactoryCounts[$i] * $p1PerAifPerDay;
            }
        }

        // Distribute BIF proportionally to total demand
        $totalDemand = 0;
        foreach ($p1Demands as $d) {
            $totalDemand += array_sum($d);
        }

        $bifAllocation = [];
        $assigned = 0;
        foreach ($p1Demands as $p1TypeId => $d) {
            $share = $totalDemand > 0 ? array_sum($d) / $totalDemand : 0;
            $bifAllocation[$p1TypeId] = max(1, (int) round($bifTotal * $share));
            $assigned += $bifAllocation[$p1TypeId];
        }

        // Adjust over/under allocation
        while ($assigned > $bifTotal) {
            $maxBif  = max($bifAllocation);
            $maxType = array_search($maxBif, $bifAllocation);
            if ($bifAllocation[$maxType] > 1) {
                $bifAllocation[$maxType]--;
                $assigned--;
            } else {
                break;
            }
        }
        while ($assigned < $bifTotal) {
            $minRatio = INF;
            $minType  = array_key_first($bifAllocation);
            foreach ($p1Demands as $p1TypeId => $d) {
                $supply = $bifAllocation[$p1TypeId] * $bifOutputPerDay;
                $demand = array_sum($d);
                $ratio  = $demand > 0 ? $supply / $demand : INF;
                if ($ratio < $minRatio) {
                    $minRatio = $ratio;
                    $minType  = $p1TypeId;
                }
            }
            $bifAllocation[$minType]++;
            $assigned++;
        }

        // Calculate P1 supply and split proportionally between AIF groups
        $p1SupplyForGroup = []; // [groupIndex][p1TypeId] => supply
        foreach ($p1Demands as $p1TypeId => $d) {
            $supply     = $bifAllocation[$p1TypeId] * $bifOutputPerDay;
            $totalGroup = array_sum($d);
            for ($i = 0; $i < $inputCount; $i++) {
                $p1SupplyForGroup[$i][$p1TypeId] = $totalGroup > 0
                    ? $supply * $d[$i] / $totalGroup
                    : 0;
            }
        }

        // Effective AIF cycles limited by P1 supply for each AIF group
        $effectiveAifCycles = [];
        for ($i = 0; $i < $inputCount; $i++) {
            $effectiveAifCycles[$i] = $p2FactoryCounts[$i] * $p2CyclesPerDay;
            foreach ($p2Schematics[$i]['inputs'] as [$p1TypeId, $p1Qty]) {
                $maxCycles = ($p1SupplyForGroup[$i][$p1TypeId] ?? 0) / max(1, $p1Qty);
                $effectiveAifCycles[$i] = min($effectiveAifCycles[$i], $maxCycles);
            }
        }

        // P2 produced per day per group
        $p2Produced = [];
        for ($i = 0; $i < $inputCount; $i++) {
            $p2Produced[$i] = $effectiveAifCycles[$i] * $p2OutputPerCycle;
        }

        // Effective P3 cycles
        $maxP3ByFactory  = $p3Factories * $p3CyclesPerDay;
        $maxP3ByP2       = [];
        for ($i = 0; $i < $inputCount; $i++) {
            [, $p2Qty] = $p2Inputs[$i];
            $maxP3ByP2[$i] = $p2Produced[$i] / max(1, $p2Qty);
        }
        $effectiveP3Cycles = min($maxP3ByFactory, ...$maxP3ByP2);
        $effectiveP3Output = $effectiveP3Cycles * $p3OutputQty;

        // Trace back to actual P1 consumption
        $p2Consumed     = [];
        $p2CyclesNeeded = [];
        for ($i = 0; $i < $inputCount; $i++) {
            [, $p2Qty] = $p2Inputs[$i];
            $p2Consumed[$i]     = $effectiveP3Cycles * $p2Qty;
            $p2CyclesNeeded[$i] = $p2Consumed[$i] / max(1, $p2OutputPerCycle);
        }

        $p1DailyQtys = [];
        for ($i = 0; $i < $inputCount; $i++) {
            foreach ($p2Schematics[$i]['inputs'] as [$p1TypeId, $p1Qty]) {
                $p1DailyQtys[$p1TypeId] = ($p1DailyQtys[$p1TypeId] ?? 0) + $p2CyclesNeeded[$i] * $p1Qty;
            }
        }

        // P0 consumption: trace each P1 back to its P0 source
        $p0DailyQtys = [];
        foreach ($p1DailyQtys as $p1TypeId => $p1Qty) {
            $p0Schematic = self::SCHEMATICS_P0_TO_P1[$p1TypeId] ?? null;
            if ($p0Schematic === null) {
                return null;
            }
            $p0TypeId    = $p0Schematic['inputs'][0][0];
            $p0PerP1     = $p0Schematic['inputs'][0][1] / $p0Schematic['output_qty']; // 3000/20 = 150
            $p0DailyQtys[$p0TypeId] = ($p0DailyQtys[$p0TypeId] ?? 0) + $p1Qty * $p0PerP1;
        }

        // Fetch prices for all P0 inputs and P3 output
        $allTypeIds = array_merge(array_keys($p0DailyQtys), [$p3TypeId]);
        $prices = MarketPrice::whereIn('type_id', $allTypeIds)
            ->where('region_id', $regionId)
            ->get()
            ->keyBy('type_id');

        // Build P0 input details and total cost (using buy_max for inputs)
        $pricesAvailable  = true;
        $totalDailyP0Cost = 0.0;
        $p0InputDetails   = [];

        foreach ($p0DailyQtys as $p0TypeId => $dailyQty) {
            $price       = $prices->get($p0TypeId);
            $unitCost    = null;
            $priceSource = null;

            if ($price && $price->buy_max > 0) {
                $unitCost    = (float) $price->buy_max;
                $priceSource = 'buy_max';
            } elseif ($price && $price->average_price > 0) {
                $unitCost    = (float) $price->average_price;
                $priceSource = 'average_price';
            } elseif (isset($esiPrices[$p0TypeId])) {
                $unitCost    = $esiPrices[$p0TypeId];
                $priceSource = 'esi_average';
            } else {
                $pricesAvailable = false;
                $unitCost    = 0.0;
            }

            $dailyCost         = $unitCost * $dailyQty;
            $totalDailyP0Cost += $dailyCost;

            $p0InputDetails[] = [
                'type_id'      => $p0TypeId,
                'name'         => $this->resolveInputName($p0TypeId),
                'daily_qty'    => $dailyQty,
                'unit_cost'    => $unitCost,
                'daily_cost'   => $dailyCost,
                'has_price'    => $priceSource !== null,
                'price_source' => $priceSource,
            ];
        }

        // P3 output price (sell_min for export)
        $p3PriceRow    = $prices->get($p3TypeId);
        $p3SellMin     = null;
        $p3BuyMax      = null;
        $p3PriceSource = null;

        if ($p3PriceRow) {
            $p3SellMin = ($p3PriceRow->sell_min > 0) ? (float) $p3PriceRow->sell_min : null;
            $p3BuyMax  = ($p3PriceRow->buy_max  > 0) ? (float) $p3PriceRow->buy_max  : null;
            if ($p3SellMin !== null || $p3BuyMax !== null) {
                $p3PriceSource = $p3SellMin !== null ? 'sell_min' : 'buy_max';
            } elseif ($p3PriceRow->average_price > 0) {
                $p3SellMin     = (float) $p3PriceRow->average_price;
                $p3PriceSource = 'average_price';
            }
        }

        if ($p3SellMin === null && $p3BuyMax === null && isset($esiPrices[$p3TypeId])) {
            $p3SellMin     = $esiPrices[$p3TypeId];
            $p3PriceSource = 'esi_average';
        }

        if (! $p3SellMin && ! $p3BuyMax) {
            $pricesAvailable = false;
        }

        $dailyP3SellValue = ($p3SellMin ?? 0.0) * $effectiveP3Output;
        $dailyP3BuyValue  = ($p3BuyMax  ?? 0.0) * $effectiveP3Output;
        $dailyProfitSell  = $dailyP3SellValue - $totalDailyP0Cost;
        $dailyProfitBuy   = $dailyP3BuyValue  - $totalDailyP0Cost;
        $marginSell       = $totalDailyP0Cost > 0
            ? round($dailyProfitSell / $totalDailyP0Cost * 100, 2)
            : null;

        // Build P1 and P2 intermediate details for display
        $p1IntermediateDetails = [];
        foreach ($p1DailyQtys as $p1TypeId => $dailyQty) {
            $p1IntermediateDetails[] = [
                'type_id'   => $p1TypeId,
                'name'      => self::P1_NAMES[$p1TypeId] ?? "Type {$p1TypeId}",
                'daily_qty' => $dailyQty,
                'bif_count' => $bifAllocation[$p1TypeId] ?? 0,
            ];
        }

        // Build p2_inputs array
        $p2InputDetails = [];
        for ($i = 0; $i < $inputCount; $i++) {
            [$p2TypeId] = $p2Inputs[$i];
            $p2InputDetails[] = [
                'type_id'        => $p2TypeId,
                'name'           => self::P2_NAMES[$p2TypeId] ?? "Type {$p2TypeId}",
                'factories'      => $p2FactoryCounts[$i],
                'daily_produced' => $p2Produced[$i],
                'daily_consumed' => $p2Consumed[$i],
            ];
        }

        return [
            'output_type_id'       => $p3TypeId,
            'output_name'          => $p3Schematic['name'],
            'output_qty_per_day'   => $effectiveP3Output,
            'factory_slots'        => self::FACTORY_SLOTS,
            'factory_split'        => [
                'p3_factories'   => $p3Factories,
                'bif_total'      => $bifTotal,
                'bif_allocation' => $bifAllocation,
                'total'          => self::FACTORY_SLOTS,
            ],
            'p2_inputs'            => $p2InputDetails,
            'p1_intermediates'     => $p1IntermediateDetails,
            'p0_inputs'            => $p0InputDetails,
            'total_daily_p0_cost'  => $totalDailyP0Cost,
            'p3_sell_min'          => $p3SellMin,
            'p3_buy_max'           => $p3BuyMax,
            'p3_price_source'      => $p3PriceSource,
            'daily_p3_sell_value'  => $dailyP3SellValue,
            'daily_p3_buy_value'   => $dailyP3BuyValue,
            'daily_profit_sell'    => $dailyProfitSell,
            'daily_profit_buy'     => $dailyProfitBuy,
            'margin_percent_sell'  => $marginSell,
            'daily_import_value'   => $totalDailyP0Cost,
            'daily_export_value'   => $dailyP3SellValue,
            'prices_available'     => $pricesAvailable,
        ];
    }
}
