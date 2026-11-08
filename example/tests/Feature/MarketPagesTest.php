<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Station trading and region arbitrage moved to the React app; the old URLs
 * and route names redirect there, and their /api aliases are gone.
 */
class MarketPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_trading_pages_redirect_to_the_app(): void
    {
        $user = User::create(['name' => 'Pilot', 'email' => 'p@test.com', 'is_active' => true]);

        $this->actingAs($user)->get(route('market.station-trading'))->assertRedirect('/app/market/station-trading');
        $this->actingAs($user)->get(route('market.region-arbitrage'))->assertRedirect('/app/market/region-arbitrage');
    }

    public function test_legacy_trading_api_aliases_are_removed(): void
    {
        $user = User::create(['name' => 'Pilot', 'email' => 'p@test.com', 'is_active' => true]);

        $this->actingAs($user)->getJson('/api/market/station-trading')->assertNotFound();
        $this->actingAs($user)->getJson('/api/market/region-arbitrage')->assertNotFound();
    }
}
