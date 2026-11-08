<?php

namespace App\Http\Controllers\Api\V1\Programs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Programs\LocationFilterRequest;
use App\Http\Requests\Api\V1\Programs\MergeBuildProgramsRequest;
use App\Http\Requests\Api\V1\Programs\RenameBuildProgramRequest;
use App\Http\Requests\Api\V1\Programs\StoreBuildProgramRequest;
use App\Http\Requests\Api\V1\Programs\StoreReactionPlanProgramRequest;
use App\Http\Resources\V1\Programs\BuildProgramDetailResource;
use App\Http\Resources\V1\Programs\BuildProgramResource;
use App\Http\Resources\V1\Programs\BuildProgramSummaryResource;
use App\Http\Responses\ApiResponse;
use App\Models\BuildProgram;
use App\Models\EveType;
use App\Services\Programs\BuildProgramWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Personal build programs: create, merge, rename, location filter, delete.
 * Every program belongs to the logged-in user; other users' programs are 404.
 */
class BuildProgramController extends Controller
{
    public function __construct(private BuildProgramWriter $programs) {}

    /**
     * List your build programs.
     *
     * Newest change first, without steps. `location_ids` is the saved stock
     * location filter that progress should use.
     *
     * @response array{data: list<BuildProgramSummaryResource>, meta: object}
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            BuildProgramSummaryResource::collection($request->user()->buildPrograms()->orderByDesc('updated_at')->get()),
        );
    }

    /**
     * Show a build program.
     *
     * With its step tree. `meta.volumes` maps the type id of every material
     * in the tree to its packaged volume in m3. Someone else's program is 404.
     *
     * @response array{data: BuildProgramDetailResource, meta: array{volumes: array<string, float>}}
     */
    public function show(BuildProgram $program): JsonResponse
    {
        $typeIds = $this->materialTypeIds($program->steps ?? []);
        $volumes = $typeIds === []
            ? []
            : EveType::whereIn('type_id', array_keys($typeIds))
                ->get(['type_id', 'volume', 'packaged_volume'])
                ->mapWithKeys(fn ($t) => [$t->type_id => (float) ($t->packaged_volume ?? $t->volume ?? 0)])
                ->all();

        return ApiResponse::success(new BuildProgramDetailResource($program), ['volumes' => (object) $volumes]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, true>
     */
    private function materialTypeIds(array $steps): array
    {
        $ids = [];
        foreach ($steps as $step) {
            foreach ($step['materials'] ?? [] as $material) {
                if (! empty($material['type_id'])) {
                    $ids[(int) $material['type_id']] = true;
                }
            }
            $ids += $this->materialTypeIds($step['children'] ?? []);
        }

        return $ids;
    }

    /**
     * Create a build program.
     *
     * Resolves each step's materials into child steps and tags each step
     * with its manufacturing or reaction blueprint. Returns 201.
     *
     * @response array{data: BuildProgramResource, meta: object}
     */
    public function store(StoreBuildProgramRequest $request): JsonResponse
    {
        // refresh() loads the column defaults (program_type) the insert left out.
        $program = $this->programs->create($request->user(), $request->programData())->refresh();

        return ApiResponse::success(new BuildProgramResource($program), status: 201);
    }

    /**
     * Create a reaction build program from reaction plan entries.
     *
     * Each entry becomes a top-level step with its intermediate reactions as
     * children. Entries without a known reaction formula are skipped; 422 when
     * none is left. Returns 201.
     *
     * @response array{data: BuildProgramResource, meta: object}
     */
    public function storeFromReactionPlan(StoreReactionPlanProgramRequest $request): JsonResponse
    {
        $program = $this->programs->createFromReactionPlan($request->user(), $request->validated());

        if ($program === null) {
            throw ValidationException::withMessages(['entries' => 'No valid reaction entries found']);
        }

        return ApiResponse::success(new BuildProgramResource($program), status: 201);
    }

    /**
     * Merge your own programs into a new program.
     *
     * The source programs are left unchanged. 422 unless at least two of the
     * ids are your programs. Returns 201 with the merged program.
     *
     * @response array{data: BuildProgramResource, meta: object}
     */
    public function merge(MergeBuildProgramsRequest $request): JsonResponse
    {
        $program = $this->programs->merge($request->user(), $request->validated());

        if ($program === null) {
            throw ValidationException::withMessages(['program_ids' => 'At least two of your own programs are required to merge']);
        }

        return ApiResponse::success(new BuildProgramResource($program), status: 201);
    }

    /**
     * Rename a build program.
     *
     * @response array{data: BuildProgramResource, meta: object}
     */
    public function update(RenameBuildProgramRequest $request, BuildProgram $program): JsonResponse
    {
        $this->programs->rename($program, $request->validated('name'));

        return ApiResponse::success(new BuildProgramResource($program));
    }

    /**
     * Set the location filter of a build program.
     *
     * Stores the ids in `settings.location_ids`; an empty or missing list
     * clears the filter.
     *
     * @response array{data: BuildProgramResource, meta: object}
     */
    public function updateLocationFilter(LocationFilterRequest $request, BuildProgram $program): JsonResponse
    {
        $this->programs->setLocationFilter($program, $request->locationIds());

        return ApiResponse::success(new BuildProgramResource($program));
    }

    /**
     * Delete a build program.
     */
    public function destroy(BuildProgram $program): Response
    {
        $this->programs->delete($program);

        return response()->noContent();
    }
}
