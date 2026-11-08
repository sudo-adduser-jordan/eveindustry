<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Moon scan and wholemap moved to the React app; the old URLs and route
 * names redirect there and their /api aliases are gone.
 */
class MoonScanWholemapPagesTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Pilot', 'email' => 'p@test.com', 'is_active' => true]);
    }

    public function test_old_pages_redirect_to_the_app(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('tools.moon-scan'))->assertRedirect('/app/tools/moon-scan');
        $this->actingAs($user)->get(route('wholemap.index'))->assertRedirect('/app/wholemap');
    }

    public function test_legacy_api_aliases_are_removed(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson('/api/tools/moon-scan', ['scan_data' => 'x'])->assertNotFound();
        $this->actingAs($user)->getJson('/api/tools/moon-scan/systems')->assertNotFound();
        $this->actingAs($user)->getJson('/api/tools/moon-scan/corporation-search?q=ab')->assertNotFound();
        $this->actingAs($user)->getJson('/api/wholemap/search-systems?q=ji')->assertNotFound();
        $this->actingAs($user)->getJson('/api/wholemap/current-location')->assertNotFound();
        $this->actingAs($user)->postJson('/api/wholemap/signatures', [])->assertNotFound();
    }
}
