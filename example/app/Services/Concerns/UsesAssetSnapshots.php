<?php

namespace App\Services\Concerns;

use App\Jobs\RefreshAssetData;
use App\Models\Character;
use App\Services\AssetSnapshotStore;
use App\Services\Esi\EsiHttpClient;
use App\Services\RefreshEligibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

trait UsesAssetSnapshots
{
    private bool $snapshotEnrichmentPending = false;

    public function usesDurableSnapshots(): bool
    {
        return (bool) config('assets.durable_snapshots', true);
    }

    /** Re-authorize every read, including pinned previous generations. */
    private function snapshotContext(int $userId, ?string $ownerType = null, ?string $version = null): array
    {
        $keys = [];
        if (! \App\Models\User::whereKey($userId)->where('is_active', true)->exists()) {
            return [collect(), []];
        }
        if ($ownerType !== 'corporate') {
            $characters = RefreshEligibility::characters($userId, 'assets')
                ->whereJsonContains('scopes', 'esi-assets.read_assets.v1')->get();
            foreach ($characters as $character) {
                $keys[] = ['personal', (int) $character->character_id];
            }
        }
        if ($ownerType !== 'personal' && $this->canFetchCorporationAssets($main = $this->mainCharacterFor($userId))) {
            $keys[] = ['corporate', (int) $main->corporation_id];
        }

        return [app(AssetSnapshotStore::class)->snapshots($keys, $version), $keys];
    }

    public function snapshotMetadata(int $userId, ?string $ownerType = null, ?string $version = null): array
    {
        [$snapshots, $keys] = $this->snapshotContext($userId, $ownerType, $version);

        return app(AssetSnapshotStore::class)->metadata($snapshots, count($keys));
    }

    private function queueMissingSnapshots(int $userId, int $complete, int $expected): void
    {
        if ($complete < $expected && RefreshEligibility::user($userId, 'assets')) {
            RefreshAssetData::dispatch($userId);
        }
    }

    private function snapshotPayload(array $payload, ?Character $main): array
    {
        if (($payload['owner_type'] ?? '') === 'corporate' && $main) {
            $payload['character_id'] = $main->character_id;
            $payload['character_name'] = $main->name;
            $payload['corporation_name'] = $main->corporation_name;
        }

        return $payload;
    }

    private function readSnapshotAssets(int $userId): array
    {
        [$snapshots, $keys] = $this->snapshotContext($userId);
        $this->queueMissingSnapshots($userId, $snapshots->count(), count($keys));
        $main = $this->mainCharacterFor($userId);

        return app(AssetSnapshotStore::class)->items($snapshots)->orderBy('item_id')->get()
            ->map(fn ($row) => $this->snapshotPayload($row->payload, $main))->all();
    }

    private function refreshSnapshots(int $userId): array
    {
        $store = app(AssetSnapshotStore::class);
        $characters = Character::where('user_id', $userId)->where('is_esi_active', true)->get();
        $structureCharacters = $characters->filter(fn ($c) => $c->hasScope('esi-universe.read_structures.v1'))->values()->all();
        foreach ($characters as $character) {
            if (! $character->hasScope('esi-assets.read_assets.v1')) {
                continue;
            }
            $store->synchronize('personal', $character->character_id, function () use ($character, $structureCharacters) {
                $this->snapshotEnrichmentPending = false;
                $raw = $this->esi->getCharacterAssets($character);
                $items = $this->enrichAssets($raw, $character, 'personal', $structureCharacters);
                $metadata = app(EsiHttpClient::class)->paginationMetadata("/characters/{$character->character_id}/assets/", $character) ?? [];
                $metadata['enrichment_pending'] = $this->snapshotEnrichmentPending;

                return compact('raw', 'items', 'metadata');
            });
        }
        if ($this->canFetchCorporationAssets($main = $characters->firstWhere('is_main', true))) {
            $store->synchronize('corporate', $main->corporation_id, function () use ($main, $structureCharacters) {
                $this->snapshotEnrichmentPending = false;
                $raw = $this->esi->getCorporationAssets($main, $main->corporation_id);
                $items = $this->enrichAssets($raw, $main, 'corporate', $structureCharacters);
                $metadata = app(EsiHttpClient::class)->paginationMetadata("/corporations/{$main->corporation_id}/assets/", $main) ?? [];
                $metadata['enrichment_pending'] = $this->snapshotEnrichmentPending;

                return compact('raw', 'items', 'metadata');
            });
        }
        if (! config('assets.legacy_cache_dual_write', false)) {
            return [];
        }
        $assets = $this->readSnapshotAssets($userId);
        // Replace remaining legacy projections only after every owner succeeds.
        Cache::forever("assets.user.{$userId}", $assets);
        Cache::forever("assets.user.{$userId}.cached_at", now()->timestamp);

        return $assets;
    }

    private function snapshotMaterialAssets(int $userId): array
    {
        [$snapshots, $keys] = $this->snapshotContext($userId);
        $this->queueMissingSnapshots($userId, $snapshots->count(), count($keys));

        return app(AssetSnapshotStore::class)->items($snapshots)
            ->selectRaw('type_id, MIN(type_name) AS type_name, SUM(quantity) AS quantity')
            ->groupBy('type_id')->get()->toArray();
    }

    private function snapshotShipAssets(int $userId): array
    {
        [$snapshots, $keys] = $this->snapshotContext($userId);
        $this->queueMissingSnapshots($userId, $snapshots->count(), count($keys));
        $main = $this->mainCharacterFor($userId);
        $types = \App\Models\EveType::select('type_id')->whereIn('group_id',
            \App\Models\InvGroup::select('group_id')->where('category_id', self::SHIP_CATEGORY_ID));

        return app(AssetSnapshotStore::class)->items($snapshots)->whereIn('type_id', $types)
            ->get()->map(fn ($row) => $this->snapshotPayload($row->payload, $main))->all();
    }

    private function filterSnapshotItems(Builder $query, ?string $search): Builder
    {
        if ($search !== null && $search !== '') {
            $pattern = '%'.mb_strtolower(str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search)).'%';
            $query->whereExists(function ($match) use ($pattern) {
                $match->selectRaw('1')->from('asset_snapshot_items as matching')
                    ->whereColumn('matching.snapshot_id', 'asset_snapshot_items.snapshot_id')
                    ->whereColumn('matching.root_item_id', 'asset_snapshot_items.root_item_id')
                    ->where(function ($q) use ($pattern) {
                        foreach (['type_name', 'name', 'location_name', 'character_name', 'owner_type'] as $field) {
                            $q->orWhereRaw("LOWER(matching.{$field}) LIKE ? ESCAPE '='", [$pattern]);
                        }
                    });
            });
        }

        return $query;
    }

    /** Lazy mode only decodes at most one requested page of asset JSON. */
    public function paginateSnapshots(int $userId, string $ownerType, ?string $search, string $sort, string $dir, int $page, int $perPage, bool $includeChildren = true, ?int $parentItemId = null, ?int $locationId = null, ?string $version = null): array
    {
        [$snapshots, $keys] = $this->snapshotContext($userId, $ownerType, $version);
        $store = app(AssetSnapshotStore::class);
        $main = $this->mainCharacterFor($userId);
        $this->queueMissingSnapshots($userId, $snapshots->count(), count($keys));
        $query = $this->filterSnapshotItems($store->items($snapshots), $search);
        $parentItemId === null ? $query->whereNull('parent_item_id') : $query->where('parent_item_id', $parentItemId);
        if ($locationId !== null) {
            $query->where('root_location_id', $locationId);
        }
        $sort = in_array($sort, ['type_name', 'quantity', 'location_name', 'character_name', 'owner_type'], true) ? $sort : 'type_name';
        $dir = $dir === 'desc' ? 'desc' : 'asc';
        $paginator = $query->orderBy($sort, $dir)->orderBy('item_id')->paginate(min(100, max(1, $perPage)), ['*'], 'page', max(1, $page));
        $selected = $paginator->getCollection();
        $childCounts = $store->items($snapshots)->whereIn('parent_item_id', $selected->pluck('item_id'))
            ->selectRaw('snapshot_id, parent_item_id, COUNT(*) AS child_count')->groupBy('snapshot_id', 'parent_item_id')->get()
            ->mapWithKeys(fn ($row) => [$row->snapshot_id.':'.$row->parent_item_id => (int) $row->child_count]);
        $nested = [];
        if ($includeChildren && $selected->isNotEmpty()) {
            $descendants = $store->items($snapshots)->whereIn('root_item_id', $selected->pluck('root_item_id'))->orderBy('item_id')->get();
            $all = $descendants->map(fn ($row) => $this->snapshotPayload($row->payload, $main))->all();
            $visit = function (array $nodes) use (&$visit, &$nested): void {
                foreach ($nodes as $node) {
                    $nested[$node['item_id']] = $node;
                    $visit($node['children'] ?? []);
                }
            };
            $visit($this->buildAssetTree($all));
        }
        $paginator->setCollection($selected->map(function ($row) use ($main, $nested, $childCounts) {
            $payload = $nested[$row->item_id] ?? $this->snapshotPayload($row->payload, $main);
            $payload['children'] ??= [];
            $payload['children_count'] = $childCounts[$row->snapshot_id.':'.$row->item_id] ?? 0;
            $payload['has_children'] = $payload['children_count'] > 0;

            return $payload;
        }));

        return [$paginator, $store->metadata($snapshots, count($keys))];
    }

    /** Grouped summaries read SQL aggregates, without decoding item payloads or invoking ESI. */
    public function snapshotLocationSummary(int $userId, string $ownerType, ?string $search = null): array
    {
        [$snapshots, $keys] = $this->snapshotContext($userId, $ownerType);
        $store = app(AssetSnapshotStore::class);
        $this->queueMissingSnapshots($userId, $snapshots->count(), count($keys));
        $query = $this->filterSnapshotItems($store->items($snapshots), $search);
        $totals = (clone $query)->selectRaw('COUNT(*) AS total_items, COALESCE(SUM(quantity), 0) AS total_quantity, COUNT(DISTINCT type_id) AS type_count')->first();
        $locations = (clone $query)->selectRaw('root_location_id, location_name, solar_system_id, solar_system_name, SUM(CASE WHEN parent_item_id IS NULL THEN 1 ELSE 0 END) AS item_count')
            ->groupBy('root_location_id', 'location_name', 'solar_system_id', 'solar_system_name')
            ->orderBy('solar_system_name')->orderBy('location_name')->get();
        $systems = [];
        foreach ($locations as $location) {
            $systemKey = $location->solar_system_id ?? 'unknown';
            $systems[$systemKey] ??= [
                'solar_system_id' => $location->solar_system_id ? (int) $location->solar_system_id : null,
                'solar_system_name' => $location->solar_system_name, 'stations' => [],
            ];
            $systems[$systemKey]['stations'][] = [
                'location_id' => (int) $location->root_location_id, 'location_name' => $location->location_name,
                'items' => [], 'item_count' => (int) $location->item_count,
            ];
        }

        return [[
            'summary' => [
                'total_items' => (int) $totals->total_items, 'total_quantity' => (int) $totals->total_quantity,
                'system_count' => count($systems), 'location_count' => $locations->count(), 'type_count' => (int) $totals->type_count,
            ],
            'solar_systems' => array_values($systems),
        ], $store->metadata($snapshots, count($keys))];
    }
}
