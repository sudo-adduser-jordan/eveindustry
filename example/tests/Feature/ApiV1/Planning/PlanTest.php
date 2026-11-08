<?php

namespace Tests\Feature\ApiV1\Planning;

use App\Models\InventionPlan;
use App\Models\ReactionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Planning\PlanningFixtures;
use Tests\TestCase;

class PlanTest extends TestCase
{
    use PlanningFixtures, RefreshDatabase;

    public function test_logged_out(): void
    {
        $this->getJson('/api/v1/invention-plans')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->postJson('/api/v1/reaction-plans', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_invention_plan_crud_with_envelope(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->postJson('/api/v1/invention-plans', $this->inventionPlanPayload())
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Ishtar')
            ->assertJsonPath('data.decryptor_key', 'none')
            ->assertJsonPath('data.facility_tax', 0)
            ->assertJsonPath('data.system_security', 'highsec');
        $id = InventionPlan::first()->id;
        $this->postJson('/api/v1/invention-plans', $this->inventionPlanPayload(['name' => 'Aaa']))->assertStatus(201);

        $list = $this->getJson('/api/v1/invention-plans')->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('meta.pagination.per_page', 50);
        $this->assertSame(['Aaa', 'Ishtar'], array_column($list->json('data'), 'name'));
        $this->getJson('/api/v1/invention-plans?per_page=1&page=2')->assertJsonPath('data.0.name', 'Ishtar')->assertJsonPath('meta.pagination.last_page', 2);
        $this->getJson('/api/v1/invention-plans?per_page=500')->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $this->getJson("/api/v1/invention-plans/$id")->assertOk()->assertJsonPath('data.id', $id);
        $this->putJson("/api/v1/invention-plans/$id", ['name' => 'Renamed', 't1_me' => 5])
            ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.t1_me', 5)->assertJsonPath('data.t2_type_id', 12005);
        $this->deleteJson("/api/v1/invention-plans/$id")->assertStatus(204)->assertNoContent();
        $this->assertDatabaseMissing('invention_plans', ['id' => $id]);
    }

    public function test_invention_plan_422_and_other_users_plan_is_404(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->postJson('/api/v1/invention-plans', ['t1_me' => 11])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['name', 't2_type_id', 't2_type_name', 't1_me']);

        $plan = $this->makeUser()->inventionPlans()->create($this->inventionPlanPayload());
        $this->getJson("/api/v1/invention-plans/{$plan->id}")->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->putJson("/api/v1/invention-plans/{$plan->id}", ['name' => 'x'])->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->deleteJson("/api/v1/invention-plans/{$plan->id}")->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->getJson('/api/v1/invention-plans/99999')->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->assertDatabaseHas('invention_plans', ['id' => $plan->id, 'name' => 'Ishtar']);
        $this->getJson('/api/v1/invention-plans')->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_reaction_plan_crud_with_envelope(): void
    {
        $this->actingAs($this->makeUser());

        $this->postJson('/api/v1/reaction-plans', $this->reactionPlanPayload())
            ->assertStatus(201)
            ->assertJsonPath('data.default_runs_per_slot', 240)
            ->assertJsonPath('data.structure_type', 'athanor')
            ->assertJsonPath('data.entries.0.formula_name', 'Fulleride');
        $id = ReactionPlan::first()->id;

        $this->getJson('/api/v1/reaction-plans')->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/v1/reaction-plans/$id")->assertOk()->assertJsonPath('data.id', $id);
        $this->putJson("/api/v1/reaction-plans/$id", ['name' => 'New', 'rig_tier' => 't2'])
            ->assertOk()->assertJsonPath('data.name', 'New')->assertJsonPath('data.rig_tier', 't2');
        $this->deleteJson("/api/v1/reaction-plans/$id")->assertStatus(204);
        $this->assertSame(0, ReactionPlan::count());
    }

    public function test_reaction_plan_422_and_other_users_plan_is_404(): void
    {
        $this->actingAs($this->makeUser());
        $this->postJson('/api/v1/reaction-plans', ['name' => 'x', 'entries' => []])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['entries']);

        $plan = $this->makeUser()->reactionPlans()->create($this->reactionPlanPayload());
        $this->getJson("/api/v1/reaction-plans/{$plan->id}")->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->putJson("/api/v1/reaction-plans/{$plan->id}", ['name' => 'x'])->assertStatus(404);
        $this->deleteJson("/api/v1/reaction-plans/{$plan->id}")->assertStatus(404);
        $this->assertDatabaseHas('reaction_plans', ['id' => $plan->id, 'name' => 'Fullerides']);
    }
}
