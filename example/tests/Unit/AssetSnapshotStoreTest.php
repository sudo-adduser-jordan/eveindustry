<?php

namespace Tests\Unit;

use App\Models\AssetSnapshot;
use App\Models\AssetSnapshotItem;
use App\Models\AssetSnapshotOwner;
use App\Models\Character;
use App\Models\User;
use App\Services\AssetService;
use App\Services\AssetSnapshotStore;
use App\Services\Esi\EsiRequestException;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AssetSnapshotStoreTest extends TestCase
{
    use RefreshDatabase;

    private AssetSnapshotStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        config(['assets.durable_snapshots' => true, 'esi.coordination_store' => 'array']);
        Queue::fake();
        $this->store = new AssetSnapshotStore;
    }

    private function item(int $id, ?int $parent = null, array $overrides = []): array
    {
        return array_merge([
            'item_id' => $id, 'type_id' => 34, 'type_name' => 'Tritanium',
            'name' => null, 'quantity' => 10, 'location_id' => $parent ?? 60003760,
            'location_name' => 'Jita', 'location_flag' => 'Hangar', 'character_id' => 42,
            'character_name' => 'Pilot', 'owner_type' => 'personal', 'is_singleton' => false,
            'corporation_name' => null, 'parent_item_id' => $parent,
        ], $overrides);
    }

    private function publish(array $items, string $type = 'personal', int $ownerId = 42, array $meta = []): AssetSnapshot
    {
        $snapshot = $this->store->begin($type, $ownerId);
        $this->assertTrue($this->store->publish($snapshot, $items, $items, $meta));

        return $snapshot->fresh();
    }

    private function owner(array $overrides = []): User
    {
        $user = User::create(['name' => 'Pilot', 'email' => uniqid().'@test.com', 'is_active' => true, 'last_login_at' => now()]);
        Character::create(array_merge([
            'character_id' => 42, 'user_id' => $user->id, 'name' => 'Pilot',
            'corporation_id' => 98, 'is_main' => true, 'is_esi_active' => true,
            'scopes' => ['esi-assets.read_assets.v1'],
        ], $overrides));

        return $user;
    }

    public function test_pinned_inventory_cannot_include_two_generations_of_the_same_owner(): void
    {
        $first = $this->publish([$this->item(100)]);
        $second = $this->publish([$this->item(101)]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->store->snapshots([['personal', 42]], "{$first->id},{$second->id}");
    }

    public function test_successful_empty_generation_replaces_old_inventory_without_deleting_history(): void
    {
        $old = $this->publish([$this->item(10000000000001)]);
        $empty = $this->publish([]);
        $owner = AssetSnapshotOwner::first();
        $this->assertSame($empty->id, $owner->active_snapshot_id);
        $this->assertSame(0, $empty->item_count);
        $this->assertSame(1, AssetSnapshotItem::where('snapshot_id', $old->id)->count());
        $this->assertSame(0, $this->store->items($this->store->snapshots([['personal', 42]]))->count());
    }

    public function test_late_and_duplicate_jobs_cannot_replace_or_hide_the_current_generation(): void
    {
        $late = $this->store->begin('personal', 42);
        $new = $this->publish([$this->item(2)]);
        $this->assertFalse($this->store->publish($late, [$this->item(1)], [$this->item(1)]));
        $this->assertFalse($this->store->publish($new, [], []));
        $this->assertSame($new->id, AssetSnapshotOwner::first()->active_snapshot_id);
        $this->assertSame('complete', $new->fresh()->status);
        $this->assertSame([2], $this->store->items($this->store->snapshots([['personal', 42]]))->pluck('item_id')->all());
    }

    public function test_database_failure_rolls_back_all_items_and_preserves_the_pointer(): void
    {
        $old = $this->publish([$this->item(1)]);
        $new = $this->store->begin('personal', 42);
        DB::listen(function ($query) {
            if (preg_match('/^insert into [`"]?asset_snapshot_items/i', $query->sql)) {
                throw new \RuntimeException('injected failure after item insert');
            }
        });
        try {
            $this->store->publish($new, [$this->item(2)], [$this->item(2)]);
            $this->fail('An injected write failure must abort publication');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected failure after item insert', $e->getMessage());
        }
        $this->assertSame($old->id, AssetSnapshotOwner::first()->active_snapshot_id);
        $this->assertSame(0, AssetSnapshotItem::where('snapshot_id', $new->id)->count());
    }

    public function test_incomplete_and_cyclic_owners_are_never_published(): void
    {
        $old = $this->publish([$this->item(1)]);
        foreach ([
            [[$this->item(2)], []],
            [[$this->item(2), $this->item(2)], [$this->item(2), $this->item(2)]],
            [[$this->item(2, 3), $this->item(3, 2)], [$this->item(2, 3), $this->item(3, 2)]],
        ] as [$raw, $items]) {
            $new = $this->store->begin('personal', 42);
            try {
                $this->store->publish($new, $raw, $items);
                $this->fail('Malformed owners cannot be published');
            } catch (EsiRequestException $e) {
                $this->assertSame('incomplete', $e->outcome);
            }
            $this->assertSame($old->id, AssetSnapshotOwner::first()->active_snapshot_id);
        }
    }

    public function test_redis_eviction_preserves_inventory_and_reads_recheck_scope_and_unlink(): void
    {
        $user = $this->owner();
        $this->publish([$this->item(10000000000001)]);
        Cache::flush();
        $esi = Mockery::mock(EsiService::class);
        $esi->shouldNotReceive('getCharacterAssets');
        $service = new AssetService($esi);
        $this->assertSame(10000000000001, $service->getAssetsForUser($user->id)[0]['item_id']);
        Character::whereKey(42)->update(['scopes' => []]);
        $this->assertSame([], $service->getAssetsForUser($user->id));
        Character::whereKey(42)->update(['scopes' => ['esi-assets.read_assets.v1']]);
        $this->assertCount(1, $service->getAssetsForUser($user->id));
        Character::whereKey(42)->delete();
        $this->assertSame([], $service->getAssetsForUser($user->id));
    }

    public function test_shared_corporation_source_is_collected_once_while_source_is_fresh(): void
    {
        $calls = 0;
        $collect = function () use (&$calls) {
            $calls++;

            return ['raw' => [], 'items' => [], 'metadata' => [
                'source_updated_at' => now()->subHour()->timestamp,
                'validated_at' => now()->subMinute()->timestamp,
                'source_expires_at' => now()->addMinutes(30)->timestamp,
            ]];
        };
        $this->store->synchronize('corporate', 98, $collect);
        $this->store->synchronize('corporate', 98, $collect);
        $this->assertSame(1, $calls);
        $this->assertSame(1, AssetSnapshotOwner::count());
        $this->assertSame(1, AssetSnapshot::count());
    }

    public function test_rebuild_time_does_not_reset_source_age_and_unknown_expiry_is_stale(): void
    {
        $updatedAt = now()->subHours(3)->timestamp;
        $this->publish([], meta: ['source_updated_at' => $updatedAt, 'validated_at' => now()->subHour()->timestamp]);
        $meta = $this->store->metadata($this->store->snapshots([['personal', 42]]), 1);
        $this->assertSame(\Carbon\Carbon::createFromTimestamp($updatedAt)->toIso8601String(), $meta['source_updated_at']);
        $this->assertTrue($meta['stale']);
        $this->assertFalse($meta['pending']);
        $this->assertNotSame($meta['source_updated_at'], $meta['last_updated']);
    }

    public function test_revoked_corporation_permissions_hide_shared_snapshots_in_all_reads(): void
    {
        $user = $this->owner(['scopes' => ['esi-assets.read_corporation_assets.v1'], 'is_director_ceo' => true]);
        $this->publish([$this->item(10, overrides: ['owner_type' => 'corporate'])], 'corporate', 98);
        $service = new AssetService(Mockery::mock(EsiService::class));
        $this->assertCount(1, $service->getAssetsForUser($user->id));
        Character::whereKey(42)->update(['is_director_ceo' => false]);
        $this->assertSame([], $service->getAssetsForUser($user->id));
    }
}
