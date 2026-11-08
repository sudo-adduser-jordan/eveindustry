<?php

namespace App\Http\Controllers\Api\V1\Heavy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Heavy\ListRequest;
use App\Http\Requests\Api\V1\Heavy\MoonScanRequest;
use App\Http\Requests\Api\V1\Heavy\MoonScanSystemRequest;
use App\Http\Resources\V1\Heavy\MoonResource;
use App\Http\Resources\V1\Heavy\MoonScanSystemResource;
use App\Http\Responses\ApiResponse;
use App\Models\MoonScanSystem;
use App\Services\Heavy\HeavyServiceException;
use App\Services\Heavy\MoonScanLookupService;
use App\Services\MarketFreshnessService;
use App\Services\MarketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class MoonScanController extends Controller
{
    public function __construct(private MoonScanLookupService $scans, private MarketFreshnessService $freshness) {}

    /**
     * Analyse a moon scan.
     *
     * Parses the pasted scan, prices every moon and grades it, and stores the
     * result for the user's corporation (one stored system per system, one
     * stored moon per moon name, re-scans overwrite). Returns 201 when
     * moons were stored, 200 when the paste held no usable moon data (nothing
     * stored; see `data.errors`). Values come from market prices, so
     * `meta.freshness` is for the chosen region.
     *
     * @response array{data: array{pricing_mode: string, moons: list<MoonResource>, errors: list<string>}, meta: array{freshness: array{prices_as_of: ?string, costs_as_of: ?string, source: string, stale: bool}}}
     */
    public function store(MoonScanRequest $request): JsonResponse
    {
        $result = $this->scans->analyse(
            (int) $request->user()->id,
            $request->validated('scan_data'),
            $request->regionId(),
            $request->validated('pricing_mode'),
        );

        $stored = collect($result['moons'])->contains(fn (array $moon) => ($moon['id'] ?? null) !== null);

        return ApiResponse::success(
            [
                'pricing_mode' => $result['pricing_mode'],
                'moons' => MoonResource::collection($result['moons'])->resolve($request),
                'errors' => $result['errors'],
            ],
            ['freshness' => $this->freshness->freshnessMeta($request->regionId() ?? MarketService::DEFAULT_REGION_ID)],
            $stored ? 201 : 200,
        );
    }

    /**
     * Scanned systems.
     *
     * Systems the user's corporation has scanned, by name. Empty when the
     * user has no character.
     *
     * @response array{data: list<MoonScanSystemResource>, meta: array{pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function systems(ListRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', MoonScanSystem::class);

        return ApiResponse::paginatedArray(
            $this->scans->systemsForUser((int) $request->user()->id)->all(),
            $request->page(),
            $request->perPage(),
            MoonScanSystemResource::class,
        );
    }

    /**
     * Moons of a scanned system.
     *
     * The stored moons of one system of the user's corporation, best first.
     * `pricing_mode` re-prices them on the fly. A user without a character
     * gets 422; a system the corporation has not scanned is 404. Values come
     * from market prices, so `meta.freshness` is for the scan's region.
     *
     * @response array{data: list<MoonResource>, meta: array{system: array{name: string, region_id: ?int, pricing_mode: string, scanned_at: ?string}, pagination: array{page: int, per_page: int, total: int, last_page: int}, freshness: array{prices_as_of: ?string, costs_as_of: ?string, source: string, stale: bool}}}
     */
    public function system(MoonScanSystemRequest $request, string $system): JsonResponse
    {
        try {
            $record = $this->scans->findSystem((int) $request->user()->id, $system);
        } catch (HeavyServiceException $e) {
            abort($e->status, $e->getMessage());
        }
        Gate::authorize('view', $record);

        $result = $this->scans->systemResults($record, $request->validated('pricing_mode'));

        return ApiResponse::paginatedArray(
            $result['moons'],
            $request->page(),
            $request->perPage(),
            MoonResource::class,
            [
                'system' => [
                    'name' => $record->system_name,
                    'region_id' => $record->region_id,
                    'pricing_mode' => $result['pricing_mode'],
                    'scanned_at' => $record->last_scanned_at?->toIso8601String(),
                ],
                'freshness' => $this->freshness->freshnessMeta((int) ($record->region_id ?? MarketService::DEFAULT_REGION_ID)),
            ],
        );
    }
}
