<?php

namespace App\Http\Controllers\Api\V1\Corp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Corp\AssetIndexRequest;
use App\Http\Requests\Api\V1\Corp\AssetLocationsRequest;
use App\Http\Resources\V1\Corp\AbyssalGroupResource;
use App\Http\Resources\V1\Corp\AssetMaterialGroupResource;
use App\Http\Resources\V1\Corp\AssetResource;
use App\Http\Resources\V1\Corp\AssetShipGroupResource;
use App\Http\Resources\V1\Corp\AssetSolarSystemResource;
use App\Http\Responses\ApiResponse;
use App\Services\AbyssalService;
use App\Services\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AssetController extends Controller
{
    public function __construct(private AssetService $assets, private AbyssalService $abyssal) {}

    /**
     * Personal assets.
     *
     * Assets of all the user's characters, from the cache the background
     * refresh fills. Top-level items are paginated; items inside ships and
     * containers are nested under `children`. `meta.last_updated` is when the
     * cache was last filled (ISO 8601, null if never).
     *
     * @response array{data: list<AssetResource>, meta: array{last_updated: string|null, source_updated_at?: string|null, snapshot_version?: string|null, pending?: bool, stale?: bool, enrichment_pending?: bool, pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function index(AssetIndexRequest $request): JsonResponse
    {
        return $this->list($request, 'personal');
    }

    /**
     * Corporation assets.
     *
     * The corporation's assets as fetched by the user's main character. Needs
     * a main that is a Director or CEO with the corporation assets scope;
     * otherwise 403.
     *
     * @response array{data: list<AssetResource>, meta: array{last_updated: string|null, source_updated_at?: string|null, snapshot_version?: string|null, pending?: bool, stale?: bool, enrichment_pending?: bool, pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function corporation(AssetIndexRequest $request): JsonResponse
    {
        return $this->list($request, 'corporate');
    }

    /**
     * Personal assets by location.
     *
     * The same assets as `GET /assets`, grouped by solar system and then
     * station, both sorted by name, with totals in `summary`. Not paginated.
     *
     * @response array{data: array{summary: array{total_items: int, total_quantity: int, system_count: int, location_count: int, type_count: int}, solar_systems: list<AssetSolarSystemResource>}, meta: array{last_updated: string|null, source_updated_at?: string|null, snapshot_version?: string|null, pending?: bool, stale?: bool, enrichment_pending?: bool}}
     */
    public function byLocation(AssetLocationsRequest $request): JsonResponse
    {
        return $this->grouped($request, 'personal');
    }

    /**
     * Corporation assets by location.
     *
     * The corporation assets grouped like `GET /assets/by-location`. Same
     * permission as `GET /corporation/assets`; the 403 message says which
     * check failed.
     *
     * @response array{data: array{summary: array{total_items: int, total_quantity: int, system_count: int, location_count: int, type_count: int}, solar_systems: list<AssetSolarSystemResource>}, meta: array{last_updated: string|null, source_updated_at?: string|null, snapshot_version?: string|null, pending?: bool, stale?: bool, enrichment_pending?: bool}}
     */
    public function corporationByLocation(AssetLocationsRequest $request): JsonResponse
    {
        return $this->grouped($request, 'corporate');
    }

    /**
     * Materials.
     *
     * Mineral, PI and other material stacks in your assets, summed per type
     * and grouped, with each type priced at the market and totals in ISK.
     * `meta.corporation_assets_warning` says why corporation assets are left
     * out, or is null when they are included.
     *
     * @response array{data: array{grand_total: float, groups: list<AssetMaterialGroupResource>}, meta: array{last_updated: string|null, corporation_assets_warning: string|null}}
     */
    public function materials(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $materials = $this->assets->getMaterialsForUser($userId);

        return ApiResponse::success([
            'grand_total' => (float) $materials['grand_total'],
            'groups' => AssetMaterialGroupResource::collection($materials['groups'])->resolve($request),
        ], [
            'last_updated' => $this->assets->getLastUpdatedTimestamp($userId),
            'corporation_assets_warning' => $this->assets->getCorporationAssetsWarning($userId),
        ]);
    }

    /**
     * Ships.
     *
     * Every ship hull in your assets, grouped by ship group (Frigate,
     * Cruiser, ...) and sorted by name, with totals.
     *
     * @response array{data: array{total_ships: int, type_count: int, ship_groups: list<AssetShipGroupResource>}, meta: array{last_updated: string|null, source_updated_at?: string|null, snapshot_version?: string|null, pending?: bool, stale?: bool, enrichment_pending?: bool}}
     */
    public function ships(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $ships = $this->assets->getShipsForUser($userId);

        return ApiResponse::success([
            'total_ships' => $ships['total_ships'],
            'type_count' => $ships['type_count'],
            'ship_groups' => AssetShipGroupResource::collection($ships['ship_types'])->resolve($request),
        ], [
            'last_updated' => $this->assets->getLastUpdatedTimestamp($userId),
        ]);
    }

    /**
     * Abyssal modules.
     *
     * Mutated modules in your assets, grouped by base type and ranked by roll
     * quality within each group. Each item lists the attributes that differ
     * from the base type. Slow on a cold cache: it asks ESI about every item.
     *
     * @response array{data: list<AbyssalGroupResource>, meta: array{last_updated: string|null, source_updated_at?: string|null, snapshot_version?: string|null, pending?: bool, stale?: bool, enrichment_pending?: bool}}
     */
    public function abyssal(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        return ApiResponse::success(
            AbyssalGroupResource::collection($this->abyssal->getAbyssalItemsForUser($userId))->resolve($request),
            ['last_updated' => $this->assets->getLastUpdatedTimestamp($userId)],
        );
    }

    /**
     * Refresh assets.
     *
     * Queues a background refresh that respects upstream freshness for
     * refresh of personal and corporation assets. Answers 202 at once; the
     * current list stays readable until the refresh replaces it, and
     * `meta.last_updated` changes when it has.
     */
    public function refresh(Request $request): Response
    {
        $this->assets->queueRefresh((int) $request->user()->id);

        return response()->noContent(202);
    }

    private function grouped(AssetLocationsRequest $request, string $ownerType): JsonResponse
    {
        $userId = (int) $request->user()->id;
        if (! $request->includeItems() && $this->assets->usesDurableSnapshots()) {
            [$grouped, $meta] = $this->assets->snapshotLocationSummary($userId, $ownerType, $request->search());

            return ApiResponse::success([
                'summary' => $grouped['summary'],
                'solar_systems' => AssetSolarSystemResource::collection($grouped['solar_systems'])->resolve($request),
            ], $meta);
        }
        $grouped = $this->assets->getSolarSystemGroupedAssets($userId, $request->search(), $ownerType);
        if (! $request->includeItems()) {
            foreach ($grouped['solar_systems'] as &$system) {
                foreach ($system['stations'] as &$station) {
                    $station['item_count'] = count($station['items']);
                    $station['items'] = [];
                }
                unset($station);
            }
            unset($system);
        }

        return ApiResponse::success([
            'summary' => $grouped['summary'],
            'solar_systems' => AssetSolarSystemResource::collection($grouped['solar_systems'])->resolve($request),
        ], [
            'last_updated' => $this->assets->getLastUpdatedTimestamp($userId),
        ]);
    }

    private function list(AssetIndexRequest $request, string $ownerType): JsonResponse
    {
        $userId = (int) $request->user()->id;
        if ($this->assets->usesDurableSnapshots()) {
            [$paginator, $meta] = $this->assets->paginateSnapshots(
                $userId, $ownerType, $request->search(), $request->sort(), $request->dir(),
                $request->page(), $request->perPage(), $request->includeChildren(),
                $request->parentItemId(), $request->locationId(), $request->snapshotVersion()
            );

            return ApiResponse::paginated($paginator, AssetResource::class, $meta);
        }

        $tree = $this->assets->searchAssets($userId, $request->search(), $request->sort(), $request->dir(), $ownerType);
        if ($request->parentItemId() !== null) {
            $find = function (array $items) use (&$find, $request): array {
                foreach ($items as $item) {
                    if ($item['item_id'] === $request->parentItemId()) {
                        return $item['children'] ?? [];
                    }
                    if ($found = $find($item['children'] ?? [])) {
                        return $found;
                    }
                }

                return [];
            };
            $tree = $find($tree);
        } elseif ($request->locationId() !== null) {
            $tree = array_values(array_filter($tree, fn ($row) => (int) $row['location_id'] === $request->locationId()));
        }
        if (! $request->includeChildren()) {
            $tree = array_map(function ($item) {
                $item['children_count'] = count($item['children'] ?? []);
                $item['has_children'] = $item['children_count'] > 0;
                $item['children'] = [];

                return $item;
            }, $tree);
        }

        return ApiResponse::paginatedArray($tree, $request->page(), $request->perPage(), AssetResource::class, [
            'last_updated' => $this->assets->getLastUpdatedTimestamp($userId),
        ]);
    }
}
