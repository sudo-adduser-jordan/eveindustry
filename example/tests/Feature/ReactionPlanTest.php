<?php

namespace Tests\Feature;

use App\Models\ReactionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReactionPlanTest extends TestCase
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

    public function test_user_can_list_plans(): void
    {
        ReactionPlan::create([
            'user_id' => $this->user->id,
            'name' => 'My Composites Plan',
            'entries' => [
                ['formula_type_id' => 99001, 'formula_name' => 'Sylramic Fibers', 'slots' => 6, 'runs_per_slot' => 240],
            ],
            'default_runs_per_slot' => 240,
            'rig_bonus' => 2.4,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reaction-plans');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'My Composites Plan');
    }

    public function test_user_can_store_plan(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reaction-plans', [
                'name' => 'New Plan',
                'entries' => [
                    ['formula_type_id' => 99001, 'formula_name' => 'Sylramic Fibers', 'slots' => 6, 'runs_per_slot' => 240],
                ],
                'default_runs_per_slot' => 240,
                'rig_bonus' => 2.4,
                'structure_type' => 'tatara',
                'rig_tier' => 't2',
                'security_space' => 'nullsec',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'New Plan')
            ->assertJsonPath('data.structure_type', 'tatara')
            ->assertJsonPath('data.rig_tier', 't2')
            ->assertJsonPath('data.security_space', 'nullsec');

        $this->assertDatabaseHas('reaction_plans', [
            'user_id' => $this->user->id,
            'name' => 'New Plan',
            'structure_type' => 'tatara',
            'rig_tier' => 't2',
            'security_space' => 'nullsec',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reaction-plans', []);

        $response->assertUnprocessable();
    }

    public function test_store_validates_entries_structure(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reaction-plans', [
                'name' => 'Bad Plan',
                'entries' => [
                    ['formula_type_id' => 99001],  // missing required fields
                ],
            ]);

        $response->assertUnprocessable();
    }

    public function test_user_can_show_plan(): void
    {
        $plan = ReactionPlan::create([
            'user_id' => $this->user->id,
            'name' => 'My Plan',
            'entries' => [
                ['formula_type_id' => 99001, 'formula_name' => 'Sylramic Fibers', 'slots' => 6, 'runs_per_slot' => 240],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reaction-plans/'.$plan->id);

        $response->assertOk()
            ->assertJsonPath('data.name', 'My Plan')
            ->assertJsonPath('data.entries.0.formula_name', 'Sylramic Fibers');
    }

    public function test_user_cannot_show_other_users_plan(): void
    {
        $otherUser = User::create([
            'name' => 'Other',
            'email' => 'other@example.com',
            'is_active' => true,
        ]);

        $plan = ReactionPlan::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Plan',
            'entries' => [
                ['formula_type_id' => 99001, 'formula_name' => 'Test', 'slots' => 1, 'runs_per_slot' => 240],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reaction-plans/'.$plan->id);

        $response->assertNotFound();
    }

    public function test_user_can_update_plan(): void
    {
        $plan = ReactionPlan::create([
            'user_id' => $this->user->id,
            'name' => 'Old Name',
            'entries' => [
                ['formula_type_id' => 99001, 'formula_name' => 'Test', 'slots' => 1, 'runs_per_slot' => 240],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/reaction-plans/'.$plan->id, [
                'name' => 'New Name',
                'entries' => [
                    ['formula_type_id' => 99001, 'formula_name' => 'Sylramic Fibers', 'slots' => 6, 'runs_per_slot' => 480],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertDatabaseHas('reaction_plans', [
            'id' => $plan->id,
            'name' => 'New Name',
        ]);
    }

    public function test_user_cannot_update_other_users_plan(): void
    {
        $otherUser = User::create([
            'name' => 'Other',
            'email' => 'other@example.com',
            'is_active' => true,
        ]);

        $plan = ReactionPlan::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Plan',
            'entries' => [
                ['formula_type_id' => 99001, 'formula_name' => 'Test', 'slots' => 1, 'runs_per_slot' => 240],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/reaction-plans/'.$plan->id, [
                'name' => 'Hacked',
            ]);

        $response->assertNotFound();
    }

    public function test_user_can_delete_plan(): void
    {
        $plan = ReactionPlan::create([
            'user_id' => $this->user->id,
            'name' => 'Delete Me',
            'entries' => [
                ['formula_type_id' => 99001, 'formula_name' => 'Test', 'slots' => 1, 'runs_per_slot' => 240],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/v1/reaction-plans/'.$plan->id);

        $response->assertNoContent();
        $this->assertDatabaseMissing('reaction_plans', ['id' => $plan->id]);
    }

    public function test_user_cannot_delete_other_users_plan(): void
    {
        $otherUser = User::create([
            'name' => 'Other',
            'email' => 'other@example.com',
            'is_active' => true,
        ]);

        $plan = ReactionPlan::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Plan',
            'entries' => [
                ['formula_type_id' => 99001, 'formula_name' => 'Test', 'slots' => 1, 'runs_per_slot' => 240],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/v1/reaction-plans/'.$plan->id);

        $response->assertNotFound();
        $this->assertDatabaseHas('reaction_plans', ['id' => $plan->id]);
    }

    public function test_deleting_user_cascades_to_reaction_plans(): void
    {
        ReactionPlan::create([
            'user_id' => $this->user->id,
            'name' => 'Plan',
            'entries' => [
                ['formula_type_id' => 99001, 'formula_name' => 'Test', 'slots' => 1, 'runs_per_slot' => 240],
            ],
        ]);

        $this->user->delete();

        $this->assertDatabaseMissing('reaction_plans', ['user_id' => $this->user->id]);
    }

    public function test_unauthenticated_user_cannot_access_plans(): void
    {
        $response = $this->getJson('/api/v1/reaction-plans');

        $response->assertUnauthorized();
    }

    public function test_plans_ordered_by_name(): void
    {
        ReactionPlan::create([
            'user_id' => $this->user->id,
            'name' => 'Zebra Plan',
            'entries' => [['formula_type_id' => 99001, 'formula_name' => 'Test', 'slots' => 1, 'runs_per_slot' => 240]],
        ]);

        ReactionPlan::create([
            'user_id' => $this->user->id,
            'name' => 'Alpha Plan',
            'entries' => [['formula_type_id' => 99002, 'formula_name' => 'Test2', 'slots' => 1, 'runs_per_slot' => 240]],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/reaction-plans');

        $response->assertOk();
        $plans = $response->json('data');
        $this->assertEquals('Alpha Plan', $plans[0]['name']);
        $this->assertEquals('Zebra Plan', $plans[1]['name']);
    }

    public function test_user_can_store_plan_with_facility_tax(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reaction-plans', [
                'name' => 'Taxed Plan',
                'entries' => [
                    ['formula_type_id' => 99001, 'formula_name' => 'Sylramic Fibers', 'slots' => 6, 'runs_per_slot' => 240],
                ],
                'default_runs_per_slot' => 240,
                'rig_bonus' => 2.4,
                'structure_type' => 'tatara',
                'rig_tier' => 't2',
                'security_space' => 'nullsec',
                'facility_tax' => 10.5,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Taxed Plan')
            ->assertJsonPath('data.facility_tax', 10.5);

        $this->assertDatabaseHas('reaction_plans', [
            'user_id' => $this->user->id,
            'name' => 'Taxed Plan',
            'facility_tax' => 10.50,
        ]);
    }

    public function test_facility_tax_defaults_to_zero(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reaction-plans', [
                'name' => 'No Tax Plan',
                'entries' => [
                    ['formula_type_id' => 99001, 'formula_name' => 'Test', 'slots' => 1, 'runs_per_slot' => 240],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.facility_tax', 0);
    }

    public function test_user_can_update_plan_facility_tax(): void
    {
        $plan = ReactionPlan::create([
            'user_id' => $this->user->id,
            'name' => 'Old Plan',
            'entries' => [
                ['formula_type_id' => 99001, 'formula_name' => 'Test', 'slots' => 1, 'runs_per_slot' => 240],
            ],
            'facility_tax' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/reaction-plans/'.$plan->id, [
                'facility_tax' => 5.25,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.facility_tax', 5.25);
    }

    public function test_user_can_store_plan_with_station_id(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reaction-plans', [
                'name' => 'Station Plan',
                'entries' => [
                    ['formula_type_id' => 99001, 'formula_name' => 'Sylramic Fibers', 'slots' => 6, 'runs_per_slot' => 240],
                ],
                'facility_tax' => 10,
                'station_id' => 60003760,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.station_id', 60003760)
            ->assertJsonPath('data.facility_tax', 10);
    }

    public function test_station_id_defaults_to_null(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reaction-plans', [
                'name' => 'No Station Plan',
                'entries' => [
                    ['formula_type_id' => 99001, 'formula_name' => 'Test', 'slots' => 1, 'runs_per_slot' => 240],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.station_id', null);
    }

    public function test_user_can_update_plan_station_id(): void
    {
        $plan = ReactionPlan::create([
            'user_id' => $this->user->id,
            'name' => 'Old Plan',
            'entries' => [
                ['formula_type_id' => 99001, 'formula_name' => 'Test', 'slots' => 1, 'runs_per_slot' => 240],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/reaction-plans/'.$plan->id, [
                'station_id' => 60003760,
                'facility_tax' => 10,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.station_id', 60003760);
    }

    public function test_user_can_store_plan_with_price_region_id(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reaction-plans', [
                'name' => 'Amarr Priced Plan',
                'entries' => [
                    ['formula_type_id' => 99001, 'formula_name' => 'Sylramic Fibers', 'slots' => 6, 'runs_per_slot' => 240],
                ],
                'price_region_id' => 10000043,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.price_region_id', 10000043);

        $this->assertDatabaseHas('reaction_plans', [
            'user_id' => $this->user->id,
            'name' => 'Amarr Priced Plan',
            'price_region_id' => 10000043,
        ]);
    }

    public function test_price_region_id_defaults_to_null(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reaction-plans', [
                'name' => 'Default Region Plan',
                'entries' => [
                    ['formula_type_id' => 99001, 'formula_name' => 'Test', 'slots' => 1, 'runs_per_slot' => 240],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.price_region_id', null);
    }

    public function test_user_can_update_plan_price_region_id(): void
    {
        $plan = ReactionPlan::create([
            'user_id' => $this->user->id,
            'name' => 'Old Plan',
            'entries' => [
                ['formula_type_id' => 99001, 'formula_name' => 'Test', 'slots' => 1, 'runs_per_slot' => 240],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/reaction-plans/'.$plan->id, [
                'price_region_id' => 10000032,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.price_region_id', 10000032);
    }

    public function test_user_can_store_plan_with_solar_system_and_station(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/reaction-plans', [
                'name' => 'System Plan',
                'entries' => [
                    ['formula_type_id' => 99001, 'formula_name' => 'Test', 'slots' => 1, 'runs_per_slot' => 240],
                ],
                'solar_system_id' => 30000142,
                'station_id' => 60003760,
                'facility_tax' => 10,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.solar_system_id', 30000142)
            ->assertJsonPath('data.station_id', 60003760);
    }

    public function test_user_can_update_plan_solar_system_id(): void
    {
        $plan = ReactionPlan::create([
            'user_id' => $this->user->id,
            'name' => 'Old Plan',
            'entries' => [
                ['formula_type_id' => 99001, 'formula_name' => 'Test', 'slots' => 1, 'runs_per_slot' => 240],
            ],
        ]);

        $this->actingAs($this->user)
            ->putJson('/api/v1/reaction-plans/'.$plan->id, ['solar_system_id' => 30000142])
            ->assertOk()
            ->assertJsonPath('data.solar_system_id', 30000142);
    }
}
