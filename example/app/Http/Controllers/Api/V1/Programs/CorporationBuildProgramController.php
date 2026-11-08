<?php

namespace App\Http\Controllers\Api\V1\Programs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Programs\CorporationLocationFilterRequest;
use App\Http\Requests\Api\V1\Programs\MergeBuildProgramsRequest;
use App\Http\Requests\Api\V1\Programs\RenameBuildProgramRequest;
use App\Http\Requests\Api\V1\Programs\StoreBuildProgramRequest;
use App\Http\Requests\Api\V1\Programs\StoreReactionPlanProgramRequest;
use App\Http\Resources\V1\Programs\CorporationBuildProgramResource;
use App\Http\Responses\ApiResponse;
use App\Models\CorporationBuildProgram;
use App\Models\User;
use App\Services\CorporationBuildProgramService;
use App\Services\Programs\CorporationBuildProgramWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Corporate build programs. Creating and merging need the feature enabled for
 * your corporation and a Director, CEO or Factory Manager character there
 * (403 otherwise). Changing or deleting a program needs the same for the
 * program's corporation, and answers 404 when you may not (as the old routes did).
 */
class CorporationBuildProgramController extends Controller
{
    public function __construct(
        private CorporationBuildProgramWriter $programs,
        private CorporationBuildProgramService $service,
    ) {}

    /**
     * Create a corporate build program.
     *
     * Same input as a personal program; it belongs to your corporation.
     * Returns 201.
     *
     * @response array{data: CorporationBuildProgramResource, meta: object}
     */
    public function store(StoreBuildProgramRequest $request): JsonResponse
    {
        // refresh() loads the column defaults (program_type) the insert left out.
        $program = $this->programs->create($this->corporationId($request->user()), $request->user(), $request->programData())->refresh();

        return ApiResponse::success(new CorporationBuildProgramResource($program), status: 201);
    }

    /**
     * Create a corporate reaction build program from reaction plan entries.
     *
     * 422 when none of the entries has a known reaction formula. Returns 201.
     *
     * @response array{data: CorporationBuildProgramResource, meta: object}
     */
    public function storeFromReactionPlan(StoreReactionPlanProgramRequest $request): JsonResponse
    {
        $program = $this->programs->createFromReactionPlan($this->corporationId($request->user()), $request->user(), $request->validated());

        if ($program === null) {
            throw ValidationException::withMessages(['entries' => 'No valid reaction entries found']);
        }

        return ApiResponse::success(new CorporationBuildProgramResource($program), status: 201);
    }

    /**
     * Merge your corporation's programs into a new program.
     *
     * The source programs are left unchanged. 422 unless at least two of the
     * ids belong to your corporation. Returns 201 with the merged program.
     *
     * @response array{data: CorporationBuildProgramResource, meta: object}
     */
    public function merge(MergeBuildProgramsRequest $request): JsonResponse
    {
        $program = $this->programs->merge($this->corporationId($request->user()), $request->user(), $request->validated());

        if ($program === null) {
            throw ValidationException::withMessages(['program_ids' => 'At least two programs from your corporation are required to merge']);
        }

        return ApiResponse::success(new CorporationBuildProgramResource($program), status: 201);
    }

    /**
     * Rename a corporate build program.
     *
     * @response array{data: CorporationBuildProgramResource, meta: object}
     */
    public function update(RenameBuildProgramRequest $request, CorporationBuildProgram $program): JsonResponse
    {
        $this->programs->rename($program, $request->validated('name'));

        return ApiResponse::success(new CorporationBuildProgramResource($program));
    }

    /**
     * Set the location filters of a corporate build program.
     *
     * Stores `settings.input_location_ids` (where materials are counted) and
     * `settings.output_location_ids` (where finished products are counted);
     * a missing list is cleared.
     *
     * @response array{data: CorporationBuildProgramResource, meta: object}
     */
    public function updateLocationFilter(CorporationLocationFilterRequest $request, CorporationBuildProgram $program): JsonResponse
    {
        $this->programs->setLocationFilter($program, $request->inputLocationIds(), $request->outputLocationIds());

        return ApiResponse::success(new CorporationBuildProgramResource($program));
    }

    /**
     * Delete a corporate build program.
     */
    public function destroy(CorporationBuildProgram $program): Response
    {
        $this->programs->delete($program);

        return response()->noContent();
    }

    /**
     * The corporation the user manages programs for; the create/merge policy
     * has already checked that it exists.
     */
    private function corporationId(User $user): int
    {
        return $this->service->getConfigForUser($user->id)->corporation_id;
    }
}
