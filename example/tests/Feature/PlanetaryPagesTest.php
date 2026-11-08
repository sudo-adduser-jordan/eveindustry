<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The planetary dashboard and factory planner moved to the React app; the
 * old URLs and route names redirect there and the old /api alias is gone.
 */
class PlanetaryPagesTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Pilot', 'email' => 'p@test.com', 'is_active' => true]);
    }

    public function test_old_pages_redirect_to_the_app(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('planetary.dashboard'))->assertRedirect('/app/planetary');
        $this->actingAs($user)->get(route('planetary.factory-planner'))->assertRedirect('/app/planetary/factory-planner');
    }

    public function test_legacy_api_alias_and_refresh_post_are_removed(): void
    {
        $user = $this->user();

        $this->actingAs($user)->getJson('/api/planetary/factory-planner/calculate')->assertNotFound();
        $this->actingAs($user)->postJson('/planetary/refresh')->assertStatus(404);
    }
}
