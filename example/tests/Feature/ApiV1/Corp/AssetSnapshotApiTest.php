<?php

namespace Tests\Feature\ApiV1\Corp;

use App\Models\Character;
use App\Models\SdeSolarSystem;
use App\Models\SdeStation;
use App\Models\User;
use App\Services\AssetSnapshotStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AssetSnapshotApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AssetSnapshotStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        config(['assets.durable_snapshots' => true, 'esi.coordination_store' => 'array']);
        Queue::fake();
        Http::preventStrayRequests();
        $this->user = User::create(['name' => 'Pilot', 'email' => 'pilot@test.com', 'last_login_at' => now(), 'is_active' => true]);
        Character::create([
            'character_id' => 42, 'user_id' => $this->user->id, 'name' => 'Pilot', 'corporation_id' => 98,
            'is_main' => true, 'is_esi_active' => true, 'is_director_ceo' => true,
            'scopes' => ['esi-assets.read_assets.v1', 'esi-assets.read_corporation_assets.v1'],
        ]);
        SdeSolarSystem::create(['solar_system_id' => 30000142, 'solar_system_name' => 'Jita']);
        SdeStation::create(['station_id' => 60003760, 'station_name' => 'Jita station', 'solar_system_id' => 30000142]);
        $this->store = new AssetSnapshotStore;
    }

    private function item(int $id, ?int $parent = null, array $overrides = []): array
    {
        return array_merge([
            'item_id' => $id, 'type_id' => 34, 'type_name' => 'Tritanium', 'name' => null, 'quantity' => 10,
            'location_id' => $parent ?? 60003760, 'location_name' => 'Jita station', 'location_flag' => 'Hangar',
            'character_id' => 42, 'character_name' => 'Pilot', 'owner_type' => 'personal',
            'parent_item_id' => $parent, 'is_singleton' => false, 'corporation_name' => null,
        ], $overrides);
    }

    private function publish(array $items, string $type = 'personal', int $owner = 42): string
    {
        $snapshot = $this->store->begin($type, $owner);
        $this->store->publish($snapshot, $items, $items, [
            'source_updated_at' => now()->subHour()->timestamp,
            'validated_at' => now()->subMinute()->timestamp,
            'source_expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return (string) $snapshot->id;
    }

    public function test_lazy_station_and_container_pages_are_bounded_and_preserve_64_bit_ids(): void
    {
        $rootId = 10000000000001;
        $items = [$this->item($rootId, overrides: ['type_name' => 'Container'])];
        for ($i = 1; $i <= 250; $i++) {
            $items[] = $this->item($rootId + $i, $rootId);
        }
        $this->publish($items);
        Cache::flush();
        DB::enableQueryLog();
        $this->actingAs($this->user)->getJson('/api/v1/assets?include_children=false&location_id=60003760')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_id', $rootId)
            ->assertJsonPath('data.0.children', [])
            ->assertJsonPath('data.0.children_count', 250)
            ->assertJsonPath('data.0.has_children', true)
            ->assertJsonPath('meta.pending', false);
        $this->actingAs($this->user)->getJson('/api/v1/assets?include_children=false&parent_item_id='.$rootId.'&per_page=50&page=2')
            ->assertOk()->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.pagination.total', 250)
            ->assertJsonPath('data.0.item_id', $rootId + 51);
        $payloadQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains(strtolower($q['query']), 'select * from "asset_snapshot_items"'));
        $this->assertTrue($payloadQueries->every(fn ($q) => str_contains(strtolower($q['query']), 'limit')));
        Http::assertNothingSent();
    }

    public function test_summary_mode_returns_sql_location_totals_without_item_json_or_http(): void
    {
        $this->publish([$this->item(1), $this->item(2, 1), $this->item(3)]);
        DB::enableQueryLog();
        $this->actingAs($this->user)->getJson('/api/v1/assets/by-location?include_items=false')
            ->assertOk()
            ->assertJsonPath('data.summary.total_items', 3)
            ->assertJsonPath('data.summary.total_quantity', 30)
            ->assertJsonPath('data.solar_systems.0.solar_system_name', 'Jita')
            ->assertJsonPath('data.solar_systems.0.stations.0.items', [])
            ->assertJsonPath('data.solar_systems.0.stations.0.item_count', 2)
            ->assertJsonPath('meta.source_updated_at', now()->subHour()->toIso8601String());
        $itemQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains(strtolower($q['query']), 'from "asset_snapshot_items"'));
        $this->assertFalse($itemQueries->contains(fn ($q) => str_contains(strtolower($q['query']), 'select *')));
        Http::assertNothingSent();
    }

    public function test_snapshot_pin_prevents_mixing_generations_and_is_reauthorized_after_scope_loss(): void
    {
        $old = $this->publish([$this->item(1), $this->item(2)]);
        $this->publish([$this->item(3)]);
        $this->actingAs($this->user)->getJson('/api/v1/assets?include_children=false&snapshot_version='.$old)
            ->assertOk()->assertJsonPath('data.*.item_id', [1, 2])->assertJsonPath('meta.snapshot_version', $old);
        Character::whereKey(42)->update(['scopes' => []]);
        $this->actingAs($this->user)->getJson('/api/v1/assets?include_children=false&snapshot_version='.$old)
            ->assertStatus(409)->assertJsonPath('code', 'conflict');
        $this->actingAs($this->user)->getJson('/api/v1/assets?include_children=false')
            ->assertOk()->assertJsonPath('data', []);
    }

    public function test_nested_search_finds_root_and_literal_wildcards_are_not_interpreted(): void
    {
        $this->publish([
            $this->item(1, overrides: ['type_name' => 'Container']),
            $this->item(2, 1, ['type_name' => 'Other container']),
            $this->item(3, 2, ['type_name' => 'Rare%ore']),
        ]);
        $this->actingAs($this->user)->getJson('/api/v1/assets?include_children=false&search=Rare')
            ->assertOk()->assertJsonPath('data.*.item_id', [1]);
        $this->actingAs($this->user)->getJson('/api/v1/assets?include_children=false&search=%25')
            ->assertOk()->assertJsonPath('data.*.item_id', [1]);
        $this->actingAs($this->user)->getJson('/api/v1/assets?include_children=false&search=_')
            ->assertOk()->assertJsonPath('data', []);
    }

    public function test_cold_reads_return_pending_and_enqueue_once_without_inline_esi(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/assets?include_children=false')
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.pending', true);
        Queue::assertPushed(\App\Jobs\RefreshAssetData::class);
        Http::assertNothingSent();
    }

    public function test_invalid_lazy_parameters_are_rejected(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/assets?include_children=nope&parent_item_id=-1&snapshot_version=other')
            ->assertStatus(422)->assertJsonValidationErrors(['include_children', 'parent_item_id', 'snapshot_version']);
        $this->actingAs($this->user)->getJson('/api/v1/assets/by-location?include_items=nope')
            ->assertStatus(422)->assertJsonValidationErrors(['include_items']);
    }
}
