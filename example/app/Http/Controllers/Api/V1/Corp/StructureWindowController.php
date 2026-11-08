<?php

namespace App\Http\Controllers\Api\V1\Corp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Corp\CorporationListRequest;
use App\Http\Requests\Api\V1\Corp\StructureWindowRequest;
use App\Http\Resources\V1\Corp\StructureWindowResource;
use App\Http\Responses\ApiResponse;
use App\Models\SdeSolarSystem;
use App\Models\StructureVulnerabilityWindow;
use App\Services\EssTrackerService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The corporation's structure vulnerability windows. Needs a character in a
 * corporation (403 otherwise); another corporation's window is 403.
 */
class StructureWindowController extends Controller
{
    public function __construct(private EssTrackerService $corporations) {}

    /**
     * Structure vulnerability windows.
     *
     * The corporation's windows by system, structure type and name.
     *
     * @response array{data: list<StructureWindowResource>, meta: array{pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function index(CorporationListRequest $request): JsonResponse
    {
        $windows = StructureVulnerabilityWindow::where('corporation_id', $this->corporationId($request))
            ->orderBy('solar_system_name')
            ->orderBy('structure_type')
            ->orderBy('structure_name')
            ->orderBy('id')
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        return ApiResponse::paginated($windows, StructureWindowResource::class);
    }

    /**
     * Add a vulnerability window. Returns 201.
     *
     * @response 201 array{data: StructureWindowResource, meta: object}
     */
    public function store(StructureWindowRequest $request): JsonResponse
    {
        $window = StructureVulnerabilityWindow::create($this->attributes($request) + [
            'corporation_id' => $this->corporationId($request),
            'created_by_user_id' => $request->user()->id,
        ]);

        return ApiResponse::success(new StructureWindowResource($window), status: 201);
    }

    /**
     * Change a vulnerability window.
     *
     * @response array{data: StructureWindowResource, meta: object}
     */
    public function update(StructureWindowRequest $request, StructureVulnerabilityWindow $window): JsonResponse
    {
        $this->authorizeOwnership($request, $window);
        $window->update($this->attributes($request));

        return ApiResponse::success(new StructureWindowResource($window));
    }

    /**
     * Remove a vulnerability window. Returns 204.
     */
    public function destroy(Request $request, StructureVulnerabilityWindow $window): Response
    {
        $this->authorizeOwnership($request, $window);
        $window->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(StructureWindowRequest $request): array
    {
        $v = $request->validated();

        return [
            'solar_system_id' => (int) $v['solar_system_id'],
            'solar_system_name' => SdeSolarSystem::displayName((int) $v['solar_system_id'], $v['solar_system_name'] ?? null),
            'structure_type' => $v['structure_type'],
            'structure_name' => $v['structure_name'],
            'day_of_week' => $v['structure_type'] === StructureVulnerabilityWindow::TYPE_SKYHOOK ? null : ($v['day_of_week'] ?? null),
            'start_time_utc' => $v['start_time_utc'],
            'duration_minutes' => (int) $v['duration_minutes'],
            'reinforce_exit_at' => isset($v['reinforce_exit_at']) ? CarbonImmutable::parse($v['reinforce_exit_at']) : null,
            'notes' => $v['notes'] ?? null,
        ];
    }

    private function corporationId(Request $request): int
    {
        return (int) $this->corporations->corporationIdForUser((int) $request->user()->id);
    }

    private function authorizeOwnership(Request $request, StructureVulnerabilityWindow $window): void
    {
        if ($this->corporationId($request) !== (int) $window->corporation_id) {
            throw new AuthorizationException('This window belongs to another corporation.');
        }
    }
}
