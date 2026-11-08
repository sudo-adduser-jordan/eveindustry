<?php

namespace App\Http\Controllers\Api\V1\Calculators;

use App\Http\Controllers\Api\V1\Calculators\Concerns\HandlesCalculatorErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Calculators\ManufacturingBrowserBrowseRequest;
use App\Http\Requests\Api\V1\Calculators\ManufacturingBrowserCalculateRequest;
use App\Http\Requests\Api\V1\Calculators\ReactionBrowserRequest;
use App\Http\Resources\V1\Calculators\ManufacturingBrowserItemResource;
use App\Http\Resources\V1\Calculators\ReactionBrowserItemResource;
use App\Http\Responses\ApiResponse;
use App\Services\Calculators\ManufacturingBrowserCalculator;
use App\Services\Calculators\ReactionBrowserCalculator;
use App\Services\MarketFreshnessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class BrowserController extends Controller
{
    use HandlesCalculatorErrors;

    /**
     * Browse precomputed manufacturing costs.
     *
     * Every blueprint in a market group (and its children) from the
     * precomputed costs, with your runs, rig bonus, facility tax and system
     * cost index applied. Sorted by profit, paginated (50 per page, max
     * 200). meta.effective_structure_me_bonus is the ME bonus applied.
     *
     * @response array{data: list<ManufacturingBrowserItemResource>, meta: array{effective_structure_me_bonus: float, freshness: array{prices_as_of: string|null, costs_as_of: string|null, source: string, stale: bool}, pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function browse(ManufacturingBrowserBrowseRequest $request, ManufacturingBrowserCalculator $browser, MarketFreshnessService $freshness): JsonResponse
    {
        return $this->manufacturingPage($browser->browse($request->calculatorInput()), $freshness->freshnessMeta($request->regionId()));
    }

    /**
     * Calculate live manufacturing profitability.
     *
     * Every T1 blueprint in a market group (or legacy category) priced from
     * current market data with your settings. One of market_group_id or
     * category_id is required. Sorted by profit, paginated (50 per page,
     * max 200).
     *
     * @response array{data: list<ManufacturingBrowserItemResource>, meta: array{effective_structure_me_bonus: float, freshness: array{prices_as_of: string|null, costs_as_of: string|null, source: string, stale: bool}, pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function calculate(ManufacturingBrowserCalculateRequest $request, ManufacturingBrowserCalculator $browser, MarketFreshnessService $freshness): JsonResponse
    {
        $result = $this->calculateOr422(fn () => $browser->calculate($request->calculatorInput()));

        return $this->manufacturingPage($result, $freshness->freshnessMeta($request->regionId()));
    }

    /**
     * Calculate reaction profitability.
     *
     * Every reaction formula with your runs, rig bonus, facility tax and
     * system cost index. Sorted by profit, paginated (50 per page, max
     * 200). meta.effective_rig_bonus is the ME bonus applied.
     *
     * @response array{data: list<ReactionBrowserItemResource>, meta: array{effective_rig_bonus: float, freshness: array{prices_as_of: string|null, costs_as_of: string|null, source: string, stale: bool}, pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function reactions(ReactionBrowserRequest $request, ReactionBrowserCalculator $browser, MarketFreshnessService $freshness): JsonResponse
    {
        $result = $browser->calculate($request->validated());

        return ApiResponse::paginatedArray(
            $result['reactions'],
            $request->page(),
            $request->perPage(),
            ReactionBrowserItemResource::class,
            [
                'effective_rig_bonus' => $result['effective_rig_bonus'],
                'freshness' => $freshness->freshnessMeta($request->regionId()),
            ],
        );
    }

    /**
     * The manufacturing browser services page their results themselves.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $freshness
     */
    private function manufacturingPage(array $result, array $freshness): JsonResponse
    {
        $paginator = new LengthAwarePaginator($result['items'], $result['total'], $result['per_page'], $result['page']);

        return ApiResponse::paginated($paginator, ManufacturingBrowserItemResource::class, [
            'effective_structure_me_bonus' => $result['effective_structure_me_bonus'],
            'freshness' => $freshness,
        ]);
    }
}
