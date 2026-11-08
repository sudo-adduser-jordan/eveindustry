<?php

namespace App\Http\Controllers\Api\V1\Corp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Corp\CorporationListRequest;
use App\Http\Requests\Api\V1\Corp\EssCycleRequest;
use App\Http\Requests\Api\V1\Corp\EssPayoutRequest;
use App\Http\Requests\Api\V1\Corp\EssSystemRequest;
use App\Http\Resources\V1\Corp\EssSystemResource;
use App\Http\Responses\ApiResponse;
use App\Models\EssTrackedSystem;
use App\Models\SdeSolarSystem;
use App\Services\EssTrackerService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The corporation ESS tracker. Everything needs a character in a corporation
 * (403 otherwise) and only touches that corporation's systems (403 for
 * another's).
 */
class EssController extends Controller
{
    public function __construct(private EssTrackerService $ess) {}

    /**
     * Corporation ESS tracker.
     *
     * Systems the user's corporation tracks, by name, with the next payout
     * and the five latest recorded payouts. The corporation is the main
     * character's (or any character's when there is no main); without one,
     * 403.
     *
     * @response array{data: list<EssSystemResource>, meta: array{pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function index(CorporationListRequest $request): JsonResponse
    {
        $corporationId = (int) $this->ess->corporationIdForUser((int) $request->user()->id);

        return ApiResponse::paginated(
            $this->ess->paginateSystemsForCorporation($corporationId, $request->perPage(), $request->page()),
            EssSystemResource::class,
        );
    }

    /**
     * Track a system.
     *
     * Adds the system to the corporation's tracker, or changes its cycle when
     * it is tracked already. Returns 201.
     *
     * @response 201 array{data: EssSystemResource, meta: object}
     */
    public function store(EssSystemRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $cycleMinutes = $this->ess->resolveCycleMinutes($validated);

        $system = EssTrackedSystem::updateOrCreate(
            [
                'corporation_id' => $this->corporationId($request),
                'solar_system_id' => (int) $validated['solar_system_id'],
            ],
            [
                'solar_system_name' => SdeSolarSystem::displayName((int) $validated['solar_system_id'], $validated['solar_system_name'] ?? null),
                'cycle_minutes' => $cycleMinutes,
                'cycle_anchor_at' => $this->ess->resolveCycleAnchor($validated, $cycleMinutes),
                'notes' => $validated['notes'] ?? null,
                'created_by_user_id' => $request->user()->id,
            ],
        );

        return ApiResponse::success(new EssSystemResource($system->load($this->recent())), status: 201);
    }

    /**
     * Change a tracked system's cycle.
     *
     * Without a next payout time or anchor the current anchor stays.
     *
     * @response array{data: EssSystemResource, meta: object}
     */
    public function update(EssCycleRequest $request, EssTrackedSystem $system): JsonResponse
    {
        $this->authorizeOwnership($request, $system);
        $validated = $request->validated();
        $cycleMinutes = $this->ess->resolveCycleMinutes($validated);

        $system->update([
            'cycle_minutes' => $cycleMinutes,
            'cycle_anchor_at' => $this->ess->resolveCycleAnchor($validated, $cycleMinutes, $system->cycle_anchor_at),
            'notes' => $validated['notes'] ?? null,
        ]);

        return ApiResponse::success(new EssSystemResource($system->load($this->recent())));
    }

    /**
     * Stop tracking a system. Returns 204.
     */
    public function destroy(Request $request, EssTrackedSystem $system): Response
    {
        $this->authorizeOwnership($request, $system);
        $system->delete();

        return response()->noContent();
    }

    /**
     * Record a payout.
     *
     * Returns 201 with the system, its recent payouts including this one.
     *
     * @response 201 array{data: EssSystemResource, meta: object}
     */
    public function logPayout(EssPayoutRequest $request, EssTrackedSystem $system): JsonResponse
    {
        $this->authorizeOwnership($request, $system);
        $validated = $request->validated();

        $system->payoutEvents()->create([
            'occurred_at' => isset($validated['occurred_at']) ? CarbonImmutable::parse($validated['occurred_at']) : CarbonImmutable::now(),
            'amount_isk' => $validated['amount_isk'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'recorded_by_user_id' => $request->user()->id,
        ]);

        return ApiResponse::success(new EssSystemResource($system->load($this->recent())), status: 201);
    }

    private function corporationId(Request $request): int
    {
        return (int) $this->ess->corporationIdForUser((int) $request->user()->id);
    }

    private function authorizeOwnership(Request $request, EssTrackedSystem $system): void
    {
        if ($this->corporationId($request) !== (int) $system->corporation_id) {
            throw new AuthorizationException('This system belongs to another corporation.');
        }
    }

    /**
     * @return array<string, \Closure>
     */
    private function recent(): array
    {
        return ['payoutEvents' => fn ($q) => $q->limit(EssTrackerService::RECENT_PAYOUTS)];
    }
}
