<?php

namespace App\Http\Controllers\Api\V1\Industry;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Industry\BlueprintResearchRequest;
use App\Http\Requests\Api\V1\Industry\ReactionFormulaResearchRequest;
use App\Http\Resources\V1\Industry\BlueprintResearchResource;
use App\Http\Resources\V1\Industry\ReactionFormulaResearchResource;
use App\Http\Responses\ApiResponse;
use App\Services\BlueprintResearchService;
use App\Services\ReactionFormulaResearchService;
use Illuminate\Http\JsonResponse;

class ResearchController extends Controller
{
    /**
     * Blueprint research.
     *
     * Every published tech I blueprint with the best ME/TE the user owns and
     * which characters hold it. Ownership needs the blueprint scope;
     * `meta.has_blueprint_data` is false when no character has it, and
     * `meta.characters_without_scope` lists those that lack it. Paginated
     * (the CSV export at `/industry/blueprint-research/export` takes the
     * same filters and returns every row).
     *
     * @response array{data: list<BlueprintResearchResource>, meta: array{has_blueprint_data: bool, characters_without_scope: list<array{character_id: int, name: string}>, market_groups: list<array{market_group_id: int, name: string}>, pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function blueprints(BlueprintResearchRequest $request, BlueprintResearchService $service): JsonResponse
    {
        $data = $service->getBlueprintResearchData(
            $request->user(),
            $request->search(),
            $request->marketGroupId(),
            $request->sortBy(),
            $request->sortDir(),
        );

        return ApiResponse::paginatedArray(
            $data['blueprints']->all(),
            $request->page(),
            $request->perPage(),
            BlueprintResearchResource::class,
            [
                'has_blueprint_data' => $data['hasBlueprintData'],
                'characters_without_scope' => $data['charactersWithoutScope'],
                'market_groups' => $data['marketGroups']
                    ->map(fn ($g) => ['market_group_id' => (int) $g->market_group_id, 'name' => (string) $g->name])
                    ->values()
                    ->all(),
            ],
        );
    }

    /**
     * Reaction formula research.
     *
     * Every published reaction formula with how many the user owns (in
     * hangars and locked in running reaction jobs) and which characters hold
     * them. Not paginated: there are fewer than a hundred. The CSV export is
     * at `/industry/reaction-formula-research/export`.
     *
     * @response array{data: list<ReactionFormulaResearchResource>, meta: array{has_blueprint_data: bool, characters_without_scope: list<array{character_id: int, name: string}>, categories: list<array{group_id: int, name: string}>}}
     */
    public function formulas(ReactionFormulaResearchRequest $request, ReactionFormulaResearchService $service): JsonResponse
    {
        $data = $service->getReactionFormulaData(
            $request->user(),
            $request->search(),
            $request->groupId(),
            $request->sortBy(),
            $request->sortDir(),
        );

        $categories = [];
        foreach ($data['categories'] as $groupId => $name) {
            $categories[] = ['group_id' => (int) $groupId, 'name' => (string) $name];
        }

        return ApiResponse::success(
            ReactionFormulaResearchResource::collection($data['formulas']),
            [
                'has_blueprint_data' => $data['hasBlueprintData'],
                'characters_without_scope' => $data['charactersWithoutScope'],
                'categories' => $categories,
            ],
        );
    }
}
