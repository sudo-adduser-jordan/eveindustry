<?php

namespace App\Services\Calculators;

use App\Services\MarketService;
use App\Services\ReactionBrowserService;
use App\Services\ReactionsCalculatorService;

/**
 * Turns the reaction browser settings into a ReactionBrowserService call.
 * Shared by POST /api/calculators/reaction-browser/calculate and
 * POST /api/v1/calculators/reaction-browser.
 */
class ReactionBrowserCalculator
{
    public function __construct(protected ReactionBrowserService $browser) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{effective_rig_bonus: float, reactions: array<int, array<string, mixed>>, count: int}
     */
    public function calculate(array $validated): array
    {
        $rigBonus = ReactionsCalculatorService::resolveRigMeBonus(
            $validated['rig_tier'] ?? 'none',
            $validated['security_space'] ?? 'lowsec'
        );

        $results = $this->browser->calculateAllProfitability(
            $validated['runs'],
            $rigBonus,
            (float) ($validated['facility_tax'] ?? 0),
            (float) ($validated['system_cost_index'] ?? 0),
            (int) ($validated['price_region_id'] ?? MarketService::DEFAULT_REGION_ID)
        );

        return [
            'effective_rig_bonus' => round($rigBonus, 2),
            'reactions' => $results,
            'count' => count($results),
        ];
    }
}
