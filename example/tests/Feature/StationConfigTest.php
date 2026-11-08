<?php

namespace Tests\Feature;

use App\Models\StationConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationConfigTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
    }

    public function test_user_can_list_station_configs(): void
    {
        StationConfig::create([
            'user_id' => $this->user->id,
            'name' => 'My Azbel',
            'structure_type' => 'azbel',
            'rig_me' => 't2',
            'rig_te' => 't1',
            'system_security' => 'lowsec',
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('api.v1.planning.station-configs.index'));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'My Azbel');
    }

    public function test_user_can_store_station_config(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('api.v1.planning.station-configs.store'), [
                'name' => 'Nullsec Sotiyo',
                'structure_type' => 'sotiyo',
                'rig_me' => 't2',
                'rig_te' => 't2',
                'system_security' => 'nullsec',
                'is_default' => false,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Nullsec Sotiyo')
            ->assertJsonPath('data.structure_type', 'sotiyo');

        $this->assertDatabaseHas('station_configs', [
            'user_id' => $this->user->id,
            'name' => 'Nullsec Sotiyo',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('api.v1.planning.station-configs.store'), []);

        $response->assertUnprocessable();
    }

    public function test_store_validates_enum_values(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('api.v1.planning.station-configs.store'), [
                'name' => 'Bad Config',
                'structure_type' => 'invalid',
                'rig_me' => 'none',
                'rig_te' => 'none',
                'system_security' => 'highsec',
            ]);

        $response->assertUnprocessable();
    }

    public function test_setting_default_clears_previous_default(): void
    {
        $config1 = StationConfig::create([
            'user_id' => $this->user->id,
            'name' => 'Config 1',
            'structure_type' => 'raitaru',
            'rig_me' => 'none',
            'rig_te' => 'none',
            'system_security' => 'highsec',
            'is_default' => true,
        ]);

        $this->actingAs($this->user)
            ->postJson(route('api.v1.planning.station-configs.store'), [
                'name' => 'Config 2',
                'structure_type' => 'azbel',
                'rig_me' => 't1',
                'rig_te' => 't1',
                'system_security' => 'lowsec',
                'is_default' => true,
            ]);

        $this->assertFalse($config1->fresh()->is_default);
        $this->assertDatabaseHas('station_configs', [
            'name' => 'Config 2',
            'is_default' => true,
        ]);
    }

    public function test_user_can_update_station_config(): void
    {
        $config = StationConfig::create([
            'user_id' => $this->user->id,
            'name' => 'Old Name',
            'structure_type' => 'raitaru',
            'rig_me' => 'none',
            'rig_te' => 'none',
            'system_security' => 'highsec',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson(route('api.v1.planning.station-configs.update', $config), [
                'name' => 'New Name',
                'structure_type' => 'azbel',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.structure_type', 'azbel');
    }

    public function test_user_cannot_update_other_users_config(): void
    {
        $otherUser = User::create([
            'name' => 'Other',
            'email' => 'other@example.com',
            'is_active' => true,
        ]);

        $config = StationConfig::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Config',
            'structure_type' => 'raitaru',
            'rig_me' => 'none',
            'rig_te' => 'none',
            'system_security' => 'highsec',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson(route('api.v1.planning.station-configs.update', $config), [
                'name' => 'Hacked',
            ]);

        $response->assertNotFound();
    }

    public function test_user_can_delete_station_config(): void
    {
        $config = StationConfig::create([
            'user_id' => $this->user->id,
            'name' => 'Delete Me',
            'structure_type' => 'raitaru',
            'rig_me' => 'none',
            'rig_te' => 'none',
            'system_security' => 'highsec',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson(route('api.v1.planning.station-configs.destroy', $config));

        $response->assertNoContent();
        $this->assertDatabaseMissing('station_configs', ['id' => $config->id]);
    }

    public function test_user_cannot_delete_other_users_config(): void
    {
        $otherUser = User::create([
            'name' => 'Other',
            'email' => 'other@example.com',
            'is_active' => true,
        ]);

        $config = StationConfig::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Config',
            'structure_type' => 'raitaru',
            'rig_me' => 'none',
            'rig_te' => 'none',
            'system_security' => 'highsec',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson(route('api.v1.planning.station-configs.destroy', $config));

        $response->assertNotFound();
        $this->assertDatabaseHas('station_configs', ['id' => $config->id]);
    }

    public function test_user_can_set_default_config(): void
    {
        $config1 = StationConfig::create([
            'user_id' => $this->user->id,
            'name' => 'Config 1',
            'structure_type' => 'raitaru',
            'rig_me' => 'none',
            'rig_te' => 'none',
            'system_security' => 'highsec',
            'is_default' => true,
        ]);

        $config2 = StationConfig::create([
            'user_id' => $this->user->id,
            'name' => 'Config 2',
            'structure_type' => 'azbel',
            'rig_me' => 't1',
            'rig_te' => 't1',
            'system_security' => 'lowsec',
            'is_default' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('api.v1.planning.station-configs.default', $config2));

        $response->assertOk();
        $this->assertFalse($config1->fresh()->is_default);
        $this->assertTrue($config2->fresh()->is_default);
    }

    public function test_deleting_user_cascades_to_station_configs(): void
    {
        StationConfig::create([
            'user_id' => $this->user->id,
            'name' => 'Config',
            'structure_type' => 'raitaru',
            'rig_me' => 'none',
            'rig_te' => 'none',
            'system_security' => 'highsec',
        ]);

        $this->user->delete();

        $this->assertDatabaseMissing('station_configs', ['user_id' => $this->user->id]);
    }

    public function test_unauthenticated_user_cannot_access_station_configs(): void
    {
        $response = $this->getJson(route('api.v1.planning.station-configs.index'));

        $response->assertUnauthorized();
    }

    public function test_configs_ordered_default_first_then_by_name(): void
    {
        StationConfig::create([
            'user_id' => $this->user->id,
            'name' => 'Zebra',
            'structure_type' => 'raitaru',
            'rig_me' => 'none',
            'rig_te' => 'none',
            'system_security' => 'highsec',
            'is_default' => false,
        ]);

        StationConfig::create([
            'user_id' => $this->user->id,
            'name' => 'Alpha',
            'structure_type' => 'azbel',
            'rig_me' => 'none',
            'rig_te' => 'none',
            'system_security' => 'highsec',
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('api.v1.planning.station-configs.index'));

        $response->assertOk();
        $configs = $response->json('data');
        $this->assertEquals('Alpha', $configs[0]['name']);
        $this->assertEquals('Zebra', $configs[1]['name']);
    }
}
