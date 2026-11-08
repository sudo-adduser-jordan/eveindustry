<?php

namespace App\Services\Calculators;

use App\Services\ManufacturingBrowserService;
use App\Services\MarketService;

/**
 * Turns the manufacturing browser settings (runs, ME, rig tier, security,
 * facility tax, cost index, region, page) into ManufacturingBrowserService
 * calls. Shared by the old manufacturing-browser routes and the v1
 * /calculators/manufacturing-browser endpoints. Both methods return the old
 * route's body without "success".
 */
class ManufacturingBrowserCalculator
{
    public const DEFAULT_PER_PAGE = 100;

    public function __construct(protected ManufacturingBrowserService $browser) {}

    /**
     * Live profitability for a market group (or legacy category).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     *
     * @throws CalculationException when neither market_group_id nor category_id is given
     */
    public function calculate(array $validated): array
    {
        if (empty($validated['market_group_id']) && empty($validated['category_id'])) {
            throw new CalculationException('Either market_group_id or category_id is required.', 'market_group_id');
        }

        $structureMeBonus = StructureBonuses::browserRigMe(
            $validated['rig_tier'] ?? 'none',
            $validated['security_space'] ?? 'highsec'
        );

        $result = $this->browser->calculateAllProfitability(
            $validated['runs'],
            (int) ($validated['me_level'] ?? 0),
            $structureMeBonus,
            (float) ($validated['facility_tax'] ?? 0),
            (float) ($validated['system_cost_index'] ?? 0),
            (int) ($validated['price_region_id'] ?? MarketService::DEFAULT_REGION_ID),
            isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            isset($validated['market_group_id']) ? (int) $validated['market_group_id'] : null,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE)
        );

        return $this->payload($structureMeBonus, $result);
    }

    /**
     * Precomputed costs for a market group with the user's settings applied.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function browse(array $validated): array
    {
        $structureMeBonus = StructureBonuses::browserRigMe(
            $validated['rig_tier'] ?? 'none',
            $validated['security_space'] ?? 'highsec'
        );

        $result = $this->browser->browsePrecomputed(
            (int) $validated['market_group_id'],
            (int) ($validated['price_region_id'] ?? MarketService::DEFAULT_REGION_ID),
            (int) ($validated['me_level'] ?? 0),
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            (int) ($validated['runs'] ?? 1),
            $structureMeBonus,
            (float) ($validated['facility_tax'] ?? 0),
            (float) ($validated['system_cost_index'] ?? 0)
        );

        return $this->payload($structureMeBonus, $result);
    }

    /**
     * @param  array{items: array, total: int, page: int, per_page: int, last_page: int}  $result
     * @return array<string, mixed>
     */
    private function payload(float $structureMeBonus, array $result): array
    {
        return [
            'effective_structure_me_bonus' => round($structureMeBonus, 2),
            'items' => $result['items'],
            'count' => count($result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'last_page' => $result['last_page'],
        ];
    }
}
