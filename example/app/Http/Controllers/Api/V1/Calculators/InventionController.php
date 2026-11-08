<?php

namespace App\Http\Controllers\Api\V1\Calculators;

use App\Http\Controllers\Api\V1\Calculators\Concerns\HandlesCalculatorErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Calculators\CharacterSkillsRequest;
use App\Http\Requests\Api\V1\Calculators\InventionCalculateRequest;
use App\Http\Resources\V1\Calculators\CharacterSkillsResource;
use App\Http\Resources\V1\Calculators\InventionJobResource;
use App\Http\Responses\ApiResponse;
use App\Services\Calculators\CharacterSkillsService;
use App\Services\Calculators\InventionJobCalculator;
use App\Services\InventionPlannerService;
use App\Services\MarketFreshnessService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InventionController extends Controller
{
    use HandlesCalculatorErrors;

    /**
     * Calculate an invention + T2 manufacturing chain.
     *
     * Invention probability and cost (datacores, decryptor), the T2 BPC,
     * T2 materials with buildable components, job fees and the full-buy /
     * full-build scenarios. An item whose invention chain cannot be
     * resolved is a 422 on t2_type_id.
     *
     * @response array{data: InventionJobResource, meta: array{freshness: array{prices_as_of: string|null, costs_as_of: string|null, source: string, stale: bool}}}
     */
    public function calculate(InventionCalculateRequest $request, InventionJobCalculator $jobs, MarketFreshnessService $freshness): JsonResponse
    {
        $result = $this->calculateOr422(fn () => $jobs->calculate($request->validated()));

        return ApiResponse::success(new InventionJobResource($result), [
            'freshness' => $freshness->freshnessMeta($request->regionId()),
        ]);
    }

    /**
     * Invention skills of one of your characters.
     *
     * Industry, Advanced Industry, encryption and datacore science skill
     * levels, plus every trained level by skill id. 404 when the character
     * is not one of yours.
     *
     * @response array{data: array{character_id: int, character_name: string, skills: array{industry: int, advanced_industry: int, encryption: list<array{skill_id: int, name: string, level: int}>, science: list<array{skill_id: int, name: string, level: int}>, levels: array<string, int>}}, meta: object}
     */
    public function skills(CharacterSkillsRequest $request, CharacterSkillsService $skills): JsonResponse
    {
        $character = $skills->findForUser((int) $request->user()->id, $request->characterId())
            ?? throw new NotFoundHttpException('Character not found.');

        $invention = $skills->invention($character);

        return ApiResponse::success(new CharacterSkillsResource([
            'character' => $character,
            'skills' => [
                'industry' => $invention['industry'],
                'advanced_industry' => $invention['advanced_industry'],
                'encryption' => $invention['encryption_skills'],
                'science' => $invention['science_skills'],
                'levels' => (object) $invention['skill_map'],
            ],
        ]));
    }

    /**
     * Available decryptors.
     *
     * The decryptors the invention calculator accepts as `decryptor_key`, with
     * their probability multiplier and ME, TE and run modifiers.
     *
     * @response array{data: list<array{key: string, name: string, type_id: int|null, probability_multiplier: float, me_modifier: int, te_modifier: int, run_modifier: int}>, meta: object}
     */
    public function decryptors(): JsonResponse
    {
        $rows = [];
        foreach (InventionPlannerService::getDecryptors() as $key => $decryptor) {
            $rows[] = ['key' => $key] + $decryptor;
        }

        return ApiResponse::success($rows);
    }
}
