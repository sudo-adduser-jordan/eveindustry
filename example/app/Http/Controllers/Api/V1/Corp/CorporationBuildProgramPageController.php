<?php

namespace App\Http\Controllers\Api\V1\Corp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Corp\EnableCorporationFeatureRequest;
use App\Http\Resources\V1\Corp\CorporateBuildProgramDetailResource;
use App\Http\Resources\V1\Corp\CorporateBuildProgramSummaryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Character;
use App\Models\CorporationBuildProgram;
use App\Models\EveType;
use App\Models\User;
use App\Services\CorporationBuildProgramService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The corporate build programs page: whether the feature is on for your
 * corporation, who may see and manage it, and the programs themselves.
 * Creating, renaming, merging, filtering and deleting live in
 * Programs\CorporationBuildProgramController; progress and asset locations
 * in Heavy\CorporationBuildProgramProgressController.
 */
class CorporationBuildProgramPageController extends Controller
{
    public function __construct(private CorporationBuildProgramService $service) {}

    /**
     * Corporate build programs.
     *
     * Whether corporate build programs are enabled for the corporation of one
     * of your characters, by whom, what you may do with them and the
     * programs, newest change first. `programs` is empty unless you have a
     * character in that corporation (`can_view`). `eligible_characters` are
     * yours that could enable the feature (Director or CEO with the
     * corporation assets scope). Never 403, so the page can offer to enable
     * the feature.
     *
     * @response array{data: array{enabled: bool, enabled_by_me: bool, corporation_name: string|null, provider_character_name: string|null, can_view: bool, can_manage: bool, eligible_characters: list<array{character_id: int, name: string, corporation_name: string|null}>, programs: list<CorporateBuildProgramSummaryResource>}, meta: object}
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->overview($request->user()));
    }

    /**
     * A corporate build program.
     *
     * The program with its step tree and the volume (m3) of each material
     * type. A program of another corporation is 404, as is any when the
     * feature is not enabled for yours.
     *
     * @response array{data: array{program: CorporateBuildProgramDetailResource, corporation_name: string|null, provider_character_name: string|null, can_manage: bool, volumes: array<string, float>}, meta: object}
     */
    public function show(Request $request, CorporationBuildProgram $program): JsonResponse
    {
        $user = $request->user();
        $config = $this->service->getConfigForUser((int) $user->id);

        $typeIds = [];
        $this->collectMaterialTypeIds($program->steps ?? [], $typeIds);
        $volumes = $typeIds === [] ? [] : EveType::whereIn('type_id', array_keys($typeIds))
            ->get(['type_id', 'volume', 'packaged_volume'])
            ->mapWithKeys(fn ($t) => [(string) $t->type_id => (float) ($t->packaged_volume ?? $t->volume ?? 0)])
            ->all();

        return ApiResponse::success([
            'program' => (new CorporateBuildProgramDetailResource($program))->resolve($request),
            'corporation_name' => $config->corporation_name,
            'provider_character_name' => $config->character?->name,
            'can_manage' => $this->service->canUserManage($user, $program->corporation_id),
            'volumes' => (object) $volumes,
        ]);
    }

    /**
     * Enable corporate build programs.
     *
     * Uses the given character's token for corporation asset lookups (or the
     * first eligible one). 422 when you have no eligible character or the
     * given one is not. Returns the new overview.
     *
     * @response array{data: array{enabled: bool, enabled_by_me: bool, corporation_name: string|null, provider_character_name: string|null, can_view: bool, can_manage: bool, eligible_characters: list<array{character_id: int, name: string, corporation_name: string|null}>, programs: list<CorporateBuildProgramSummaryResource>}, meta: object}
     */
    public function enable(EnableCorporationFeatureRequest $request): JsonResponse
    {
        $user = $request->user();
        $eligible = $this->service->getEligibleCharactersForUser((int) $user->id);
        $characterId = $request->characterId();
        $character = $characterId ? $eligible->firstWhere('character_id', $characterId) : $eligible->first();

        if (! $character) {
            throw ValidationException::withMessages(['character_id' => $eligible->isEmpty()
                ? 'No eligible character found. A character with the Director or CEO role and the corporation-assets ESI scope is required.'
                : 'The selected character is not eligible.']);
        }

        $this->service->enable($character, (int) $user->id);

        return ApiResponse::success($this->overview($user));
    }

    /**
     * Disable corporate build programs.
     *
     * Only the user who enabled them may (403 otherwise). Existing programs
     * are kept. Returns the new overview.
     *
     * @response array{data: array{enabled: bool, enabled_by_me: bool, corporation_name: string|null, provider_character_name: string|null, can_view: bool, can_manage: bool, eligible_characters: list<array{character_id: int, name: string, corporation_name: string|null}>, programs: list<CorporateBuildProgramSummaryResource>}, meta: object}
     */
    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();
        $config = $this->service->getConfigForUser((int) $user->id);

        if (! $config || (int) $config->enabled_by_user_id !== (int) $user->id) {
            throw new AuthorizationException('Only the user who enabled this feature can disable it.');
        }

        $this->service->disable($config->corporation_id);

        return ApiResponse::success($this->overview($user));
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(User $user): array
    {
        $config = $this->service->getConfigForUser((int) $user->id);
        $canView = $config !== null && $this->service->canUserView($user, $config->corporation_id);

        $programs = $canView
            ? CorporationBuildProgram::where('corporation_id', $config->corporation_id)->orderByDesc('updated_at')->get()
            : collect();

        return [
            'enabled' => $config !== null,
            'enabled_by_me' => $config !== null && (int) $config->enabled_by_user_id === (int) $user->id,
            'corporation_name' => $config?->corporation_name,
            'provider_character_name' => $config?->character?->name,
            'can_view' => $canView,
            'can_manage' => $canView && $this->service->canUserManage($user, $config->corporation_id),
            'eligible_characters' => $this->service->getEligibleCharactersForUser((int) $user->id)
                ->map(fn (Character $c) => [
                    'character_id' => (int) $c->character_id,
                    'name' => (string) $c->name,
                    'corporation_name' => $c->corporation_name,
                ])->values()->all(),
            'programs' => CorporateBuildProgramSummaryResource::collection($programs)->resolve(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<int, bool>  $typeIds
     */
    private function collectMaterialTypeIds(array $steps, array &$typeIds): void
    {
        foreach ($steps as $step) {
            foreach ($step['materials'] ?? [] as $material) {
                if (! empty($material['type_id'])) {
                    $typeIds[(int) $material['type_id']] = true;
                }
            }
            $this->collectMaterialTypeIds($step['children'] ?? [], $typeIds);
        }
    }
}
