<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Character and corporation assets moved to the React app; the old URLs and
 * route names now redirect there, and their /api aliases are gone.
 */
class AssetPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_asset_pages_redirect_to_the_app(): void
    {
        $user = User::create(['name' => 'Pilot', 'email' => 'p@test.com', 'is_active' => true]);

        $this->actingAs($user)->get(route('assets.index'))->assertRedirect('/app/assets');
        $this->actingAs($user)->get(route('assets.corporation.index'))->assertRedirect('/app/assets/corporation');
        $this->actingAs($user)->get(route('assets.materials'))->assertRedirect('/app/assets/materials');
        $this->actingAs($user)->get(route('assets.ships'))->assertRedirect('/app/assets/ships');
        $this->actingAs($user)->get(route('assets.abyssal'))->assertRedirect('/app/assets/abyssal');
    }

    public function test_legacy_asset_api_aliases_are_removed(): void
    {
        $user = User::create(['name' => 'Pilot', 'email' => 'p@test.com', 'is_active' => true]);

        $this->actingAs($user)->getJson('/api/assets')->assertNotFound();
        $this->actingAs($user)->getJson('/api/assets/corporation')->assertNotFound();
    }
}
