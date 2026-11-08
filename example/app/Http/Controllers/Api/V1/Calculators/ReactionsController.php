<?php

namespace App\Http\Controllers\Api\V1\Calculators;

use App\Http\Controllers\Api\V1\Calculators\Concerns\HandlesCalculatorErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Calculators\CharacterSkillsRequest;
use App\Http\Requests\Api\V1\Calculators\ReactionsCalculateRequest;
use App\Http\Resources\V1\Calculators\CharacterSkillsResource;
use App\Http\Resources\V1\Calculators\ReactionJobResource;
use App\Http\Responses\ApiResponse;
use App\Services\Calculators\CharacterSkillsService;
use App\Services\Calculators\ReactionJobCalculator;
use App\Services\MarketFreshnessService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReactionsController extends Controller
{
    use HandlesCalculatorErrors;

    /**
     * Calculate a reaction job.
     *
     * Materials with the rig bonus, reaction time, and cost/profit after
     * the SCC fee. Materials, base time and portion size come from the SDE
     * when not posted. A formula with no materials is a 422 on
     * formula_type_id.
     *
     * @response array{data: ReactionJobResource, meta: array{freshness: array{prices_as_of: string|null, costs_as_of: string|null, source: string, stale: bool}}}
     */
    public function calculate(ReactionsCalculateRequest $request, ReactionJobCalculator $jobs, MarketFreshnessService $freshness): JsonResponse
    {
        $result = $this->calculateOr422(fn () => $jobs->calculate($request->validated()));

        return ApiResponse::success(new ReactionJobResource($result), [
            'freshness' => $freshness->freshnessMeta($request->regionId()),
        ]);
    }

    /**
     * Reaction skills of one of your characters.
     *
     * 404 when the character is not one of yours.
     *
     * @response array{data: array{character_id: int, character_name: string, skills: array{reactions: int}}, meta: object}
     */
    public function skills(CharacterSkillsRequest $request, CharacterSkillsService $skills): JsonResponse
    {
        $character = $skills->findForUser((int) $request->user()->id, $request->characterId())
            ?? throw new NotFoundHttpException('Character not found.');

        return ApiResponse::success(new CharacterSkillsResource([
            'character' => $character,
            'skills' => $skills->reactions($character),
        ]));
    }
}
