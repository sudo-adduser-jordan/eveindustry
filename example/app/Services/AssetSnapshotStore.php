<?php

namespace App\Services;

use App\Models\AssetSnapshot;
use App\Models\AssetSnapshotItem;
use App\Models\AssetSnapshotOwner;
use App\Models\PlayerOwnedStructure;
use App\Models\SdeSolarSystem;
use App\Models\SdeStation;
use App\Services\Esi\EsiRequestException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Complete per-owner inventories. Readers only follow committed snapshot pointers. */
class AssetSnapshotStore
{
    public function synchronize(string $type, int $ownerId, callable $collect): void
    {
        $lock = Cache::store(config('esi.coordination_store'))->lock(
            "asset-owner.{$type}.{$ownerId}", (int) config('assets.snapshot_lock_seconds', 210)
        );
        if (! $lock->get()) {
            throw new EsiRequestException('coordination', 30);
        }
        try {
            $owner = AssetSnapshotOwner::firstOrCreate(['owner_type' => $type, 'owner_id' => $ownerId]);
            // Reuse a complete shared corporation collection while its source remains fresh.
            if ($owner->active_snapshot_id && $owner->next_due_at?->isFuture()
                && ! AssetSnapshot::find($owner->active_snapshot_id)?->enrichment_pending) {
                return;
            }
            $snapshot = $this->begin($type, $ownerId);
            try {
                $result = $collect();
                $this->publish($snapshot, $result['raw'], $result['items'], $result['metadata'] ?? []);
                $this->prune($owner->fresh());
            } catch (\Throwable $e) {
                $this->failed($snapshot, $e instanceof EsiRequestException ? $e->outcome : 'collection_failed');
                throw $e;
            }
        } finally {
            $lock->release();
        }
    }

    /** The generation is a fencing token, independent of a cache lock's lifetime. */
    public function begin(string $type, int $ownerId): AssetSnapshot
    {
        AssetSnapshotOwner::firstOrCreate(['owner_type' => $type, 'owner_id' => $ownerId]);

        return DB::transaction(function () use ($type, $ownerId) {
            $owner = AssetSnapshotOwner::where('owner_type', $type)->where('owner_id', $ownerId)->lockForUpdate()->firstOrFail();
            $owner->increment('generation');

            return AssetSnapshot::create(['owner_id' => $owner->id, 'generation' => $owner->generation, 'status' => 'building']);
        });
    }

    public function publish(AssetSnapshot $snapshot, array $raw, array $items, array $metadata = []): bool
    {
        $rawById = [];
        foreach ($raw as $item) {
            $id = $item['item_id'] ?? null;
            if (! is_int($id) || $id <= 0 || isset($rawById[$id])) {
                throw new EsiRequestException('incomplete', 30);
            }
            $rawById[$id] = $item;
        }
        if (count($rawById) !== count($items)) {
            throw new EsiRequestException('incomplete', 30);
        }
        $byId = [];
        foreach ($items as $item) {
            $id = $item['item_id'] ?? null;
            if (! is_int($id) || ! isset($rawById[$id]) || isset($byId[$id])) {
                throw new EsiRequestException('incomplete', 30);
            }
            $byId[$id] = $item;
        }
        $roots = [];
        foreach ($byId as $id => $item) {
            $path = [];
            $cursor = $id;
            while (isset($byId[$cursor]['parent_item_id']) && isset($byId[$byId[$cursor]['parent_item_id']])) {
                if (isset($path[$cursor])) {
                    throw new EsiRequestException('incomplete', 30);
                }
                $path[$cursor] = true;
                $cursor = (int) $byId[$cursor]['parent_item_id'];
            }
            $roots[$id] = $cursor;
        }
        $locations = array_unique(array_map(fn ($root) => (int) ($byId[$root]['location_id'] ?? 0), $roots));
        $stationSystems = [];
        $structureSystems = [];
        foreach (array_chunk($locations, 500) as $chunk) {
            $stationSystems += SdeStation::whereIn('station_id', $chunk)->pluck('solar_system_id', 'station_id')->all();
            $structureSystems += PlayerOwnedStructure::whereIn('structure_id', $chunk)->pluck('solar_system_id', 'structure_id')->all();
        }
        $systems = $stationSystems + $structureSystems;
        foreach ($locations as $location) {
            if ($location >= AssetService::SOLAR_SYSTEM_ID_MIN && $location < AssetService::SOLAR_SYSTEM_ID_MAX) {
                $systems[$location] = $location;
            }
        }
        $systemNames = [];
        foreach (array_chunk(array_unique(array_values($systems)), 500) as $chunk) {
            $systemNames += SdeSolarSystem::whereIn('solar_system_id', $chunk)->pluck('solar_system_name', 'solar_system_id')->all();
        }

        $claimed = DB::transaction(function () use ($snapshot) {
            $owner = AssetSnapshotOwner::whereKey($snapshot->owner_id)->lockForUpdate()->firstOrFail();
            if ($snapshot->fresh()->status !== 'building') {
                return false;
            }
            if ((int) $owner->generation !== (int) $snapshot->generation) {
                $snapshot->update(['status' => 'superseded']);

                return false;
            }
            $snapshot->update(['status' => 'staging']);

            return true;
        });
        if (! $claimed) {
            return false;
        }

        // Stage bounded transactions off-pointer. A crash can leave staging
        // rows, but can never expose an incomplete inventory to readers.
        try {
            $rows = [];
            foreach ($byId as $id => $item) {
                $rootLocation = (int) ($byId[$roots[$id]]['location_id'] ?? 0);
                $systemId = isset($systems[$rootLocation]) ? (int) $systems[$rootLocation] : null;
                $parent = $item['parent_item_id'] ?? null;
                $rows[] = [
                    'snapshot_id' => $snapshot->id, 'item_id' => $id, 'type_id' => (int) ($item['type_id'] ?? 0),
                    'quantity' => (int) ($item['quantity'] ?? 0), 'location_id' => (int) ($item['location_id'] ?? 0),
                    'parent_item_id' => $parent && isset($byId[$parent]) ? (int) $parent : null,
                    'root_item_id' => $roots[$id], 'root_location_id' => $rootLocation,
                    'solar_system_id' => $systemId, 'solar_system_name' => $systemId ? ($systemNames[$systemId] ?? "System #{$systemId}") : 'Unknown System',
                    'type_name' => mb_substr($item['type_name'] ?? 'Unknown', 0, 255),
                    'name' => isset($item['name']) ? mb_substr($item['name'], 0, 255) : null,
                    'location_name' => mb_substr($byId[$roots[$id]]['location_name'] ?? 'Unknown Location', 0, 255),
                    'character_name' => mb_substr($item['character_name'] ?? '', 0, 255),
                    'owner_type' => $item['owner_type'],
                    'payload' => json_encode($item, JSON_THROW_ON_ERROR),
                    'raw_payload' => json_encode($rawById[$id], JSON_THROW_ON_ERROR),
                ];
                if (count($rows) === 250) {
                    DB::transaction(fn () => AssetSnapshotItem::insert($rows), 3);
                    $rows = [];
                }
            }
            if ($rows !== []) {
                DB::transaction(fn () => AssetSnapshotItem::insert($rows), 3);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            \Illuminate\Support\Facades\Log::warning('Asset snapshot staging failed', [
                'snapshot_id' => $snapshot->id, 'sql_state' => $e->errorInfo[0] ?? null,
                'driver_code' => $e->errorInfo[1] ?? null,
            ]);
            throw new EsiRequestException('transient', 30);
        }

        return DB::transaction(function () use ($snapshot, $byId, $metadata) {
            $owner = AssetSnapshotOwner::whereKey($snapshot->owner_id)->lockForUpdate()->firstOrFail();
            if ($snapshot->fresh()->status !== 'staging') {
                return false;
            }
            if ((int) $owner->generation !== (int) $snapshot->generation) {
                $snapshot->update(['status' => 'superseded']);

                return false;
            }
            $sourceUpdated = $this->date($metadata['source_updated_at'] ?? null);
            $sourceExpires = $this->date($metadata['source_expires_at'] ?? null);
            $snapshot->update([
                'status' => 'complete', 'item_count' => count($byId), 'published_at' => now(),
                'source_updated_at' => $sourceUpdated, 'source_expires_at' => $sourceExpires,
                'enrichment_pending' => (bool) ($metadata['enrichment_pending'] ?? false),
            ]);
            $owner->update([
                'active_snapshot_id' => $snapshot->id, 'last_checked_at' => $this->date($metadata['validated_at'] ?? null),
                'last_succeeded_at' => now(), 'next_due_at' => $sourceExpires, 'failure_reason' => null,
            ]);

            return true;
        });
    }

    private function date(mixed $timestamp): ?Carbon
    {
        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }

    public function failed(AssetSnapshot $snapshot, string $reason): void
    {
        DB::transaction(function () use ($snapshot, $reason) {
            $snapshot->update(['status' => 'failed']);
            AssetSnapshotOwner::whereKey($snapshot->owner_id)->where('generation', $snapshot->generation)
                ->update(['failure_reason' => $reason]);
        });
    }

    /** Keep the active generation plus one previous complete generation for rollback and pinned paging. */
    public function prune(AssetSnapshotOwner $owner): void
    {
        $keep = AssetSnapshot::where('owner_id', $owner->id)->where('status', 'complete')
            ->orderByDesc('generation')->limit(max(2, (int) config('assets.retained_generations', 2)))->pluck('id')->all();
        $keep[] = $owner->active_snapshot_id;
        $expired = AssetSnapshot::where('owner_id', $owner->id)->whereNotIn('id', array_filter($keep))
            ->whereNotIn('status', ['building', 'staging'])->where('created_at', '<', now()->subDay())->limit(20)->pluck('id');
        foreach ($expired as $id) {
            do {
                $deleted = DB::transaction(function () use ($owner, $id) {
                    $current = AssetSnapshotOwner::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                    if ((int) $current->active_snapshot_id === (int) $id) {
                        return 0;
                    }
                    $ids = AssetSnapshotItem::where('snapshot_id', $id)->orderBy('id')->limit(500)->pluck('id');

                    return AssetSnapshotItem::whereIn('id', $ids)->delete();
                });
            } while ($deleted > 0);
            DB::transaction(function () use ($owner, $id) {
                $current = AssetSnapshotOwner::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                if ((int) $current->active_snapshot_id !== (int) $id) {
                    AssetSnapshot::whereKey($id)->delete();
                }
            });
        }
    }

    /** Owner keys are supplied only after checking the caller's current character ownership and access. */
    public function snapshots(array $ownerKeys, ?string $version = null): Collection
    {
        $owners = AssetSnapshotOwner::query()->where(function (Builder $q) use ($ownerKeys) {
            if ($ownerKeys === []) {
                $q->whereRaw('1 = 0');
            }
            foreach ($ownerKeys as [$type, $id]) {
                $q->orWhere(fn (Builder $q) => $q->where('owner_type', $type)->where('owner_id', $id));
            }
        })->get();
        if ($version !== null) {
            $ids = explode(',', $version);
            if (! preg_match('/^\\d+(,\\d+)*$/', $version) || count($ids) !== count(array_unique($ids))) {
                abort(409, 'This asset inventory version is no longer available. Reload the list.');
            }
            $snapshots = AssetSnapshot::whereIn('owner_id', $owners->pluck('id'))->whereIn('id', $ids)
                ->where('status', 'complete')->orderBy('id')->get();
            if ($snapshots->count() !== count($ids) || $snapshots->pluck('owner_id')->unique()->count() !== $snapshots->count()) {
                abort(409, 'This asset inventory version is no longer available. Reload the list.');
            }

            return $snapshots;
        }

        return AssetSnapshot::whereIn('id', $owners->pluck('active_snapshot_id')->filter())->where('status', 'complete')->orderBy('id')->get();
    }

    public function items(Collection $snapshots): Builder
    {
        return AssetSnapshotItem::whereIn('snapshot_id', $snapshots->pluck('id'));
    }

    public function metadata(Collection $snapshots, int $expectedOwners): array
    {
        $unknownSource = $snapshots->contains(fn ($snapshot) => $snapshot->source_updated_at === null);

        return [
            'last_updated' => $snapshots->max('published_at')?->toIso8601String(),
            'source_updated_at' => $unknownSource ? null : $snapshots->min('source_updated_at')?->toIso8601String(),
            'snapshot_version' => $snapshots->isEmpty() ? null : $snapshots->pluck('id')->implode(','),
            'pending' => $snapshots->count() < $expectedOwners,
            'stale' => $snapshots->isEmpty() || $snapshots->contains(fn ($snapshot) => ! $snapshot->source_expires_at || $snapshot->source_expires_at->isPast()),
            'enrichment_pending' => $snapshots->contains(fn ($snapshot) => $snapshot->enrichment_pending),
        ];
    }
}
