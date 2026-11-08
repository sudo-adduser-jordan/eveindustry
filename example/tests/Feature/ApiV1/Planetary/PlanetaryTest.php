<?php

namespace Tests\Feature\ApiV1\Planetary;

use App\Models\User;
use App\Services\PlanetaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanetaryTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Pilot', 'email' => 'p@test.com', 'is_active' => true]);
    }

    private function dashboard(): array
    {
        $planet = [
            'planet_id' => 40000001, 'planet_name' => 'Jita I', 'planet_type' => 'barren', 'upgrade_level' => 4,
            'num_pins' => 5, 'last_update' => '2026-10-21T10:00:00Z',
            'summary' => ['extractor_count' => 1, 'factory_count' => 2, 'storage_count' => 0, 'launchpad_count' => 1,
                'total_pins' => 5, 'total_routes' => 1, 'total_links' => 0],
            'pins' => [
                ['pin_id' => 1, 'type_id' => 2848, 'type_name' => 'ECU', 'category' => 'extractor', 'contents' => [],
                    'extractor' => ['product_type_id' => 2268, 'product_name' => 'Aqueous Liquids', 'qty_per_cycle' => 100,
                        'cycle_time' => 900, 'per_hour' => 400.0, 'avg_per_hour' => 300.0, 'total_output' => 1, 'total_cycles' => 1,
                        'expiry_time' => '2026-10-22T10:00:00Z', 'install_time' => '2026-10-21T10:00:00Z', 'is_active' => true]],
                ['pin_id' => 2, 'type_id' => 2469, 'type_name' => 'Basic', 'category' => 'factory', 'contents' => [],
                    'factory' => ['schematic_id' => 121, 'schematic_name' => 'Water', 'cycle_time' => 1800]],
                ['pin_id' => 3, 'type_id' => 2256, 'type_name' => 'Launchpad', 'category' => 'launchpad', 'contents' => [],
                    'storage' => ['capacity' => 10000.0, 'used_volume' => 2500.0, 'usage_percent' => 25.0, 'total_items' => 10,
                        'items' => [['type_id' => 2268, 'name' => 'Aqueous Liquids', 'amount' => 10, 'volume' => 0.1]]]],
            ],
            'outputs' => [['type_id' => 2268, 'name' => 'Aqueous Liquids', 'per_hour' => 400.0, 'source' => 'extractor']],
            'inputs' => [],
            'alerts' => [['type' => 'extractor_expired', 'severity' => 'warning', 'message' => 'Extractor for X has expired', 'pin_id' => 1]],
            'routes' => [['content_type_id' => 2268, 'content_name' => 'Aqueous Liquids', 'quantity' => 10, 'source_pin_id' => 1, 'destination_pin_id' => 3]],
        ];

        return [
            'characters' => [['character_id' => 1, 'character_name' => 'Ana', 'portrait_url' => 'http://p', 'planets' => [$planet]]],
            'summary' => ['total_planets' => 1, 'total_extractors' => 1, 'total_factories' => 2, 'total_characters' => 1],
            'outputs' => [['type_id' => 2268, 'name' => 'Aqueous Liquids', 'total_per_hour' => 400.0, 'planets' => 1]],
            'alerts' => [['type' => 'extractor_expired', 'severity' => 'warning', 'message' => 'Extractor for X has expired',
                'pin_id' => 1, 'character_name' => 'Ana', 'planet_name' => 'Jita I']],
        ];
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/planetary')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->postJson('/api/v1/planetary/refresh')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_dashboard_shape(): void
    {
        $user = $this->user();
        $data = $this->dashboard();
        $this->mock(PlanetaryService::class, function ($mock) use ($user, $data) {
            $mock->shouldReceive('getPlanetaryDataForUser')->once()->with($user->id)->andReturn($data);
            $mock->shouldReceive('buildCapacityAnalysis')->once()->andReturn([[
                'character_name' => 'Ana', 'planet_name' => 'Jita I', 'planet_type' => 'barren',
                'extractor_count' => 1, 'factory_count' => 2, 'status' => 'balanced',
            ]]);
        });

        $this->actingAs($user)->getJson('/api/v1/planetary')
            ->assertOk()
            ->assertJsonPath('data.summary', ['characters' => 1, 'planets' => 1, 'extractors' => 1, 'factories' => 2])
            ->assertJsonPath('data.outputs.0.per_hour', 400)
            ->assertJsonPath('data.alerts.0.planet_name', 'Jita I')
            ->assertJsonPath('data.capacity.0.status', 'balanced')
            ->assertJsonPath('data.characters.0.planets.0.planet_type', 'barren')
            ->assertJsonPath('data.characters.0.planets.0.route_count', 1)
            ->assertJsonPath('data.characters.0.planets.0.extractors.0.product_name', 'Aqueous Liquids')
            ->assertJsonPath('data.characters.0.planets.0.extractors.0.is_active', true)
            ->assertJsonPath('data.characters.0.planets.0.factories.0.schematic_name', 'Water')
            ->assertJsonPath('data.characters.0.planets.0.storages.0.kind', 'launchpad')
            ->assertJsonPath('data.characters.0.planets.0.storages.0.items.0.amount', 10);
    }

    public function test_refresh_clears_the_cache(): void
    {
        $user = $this->user();
        $this->mock(PlanetaryService::class, function ($mock) use ($user) {
            $mock->shouldReceive('clearUserCache')->once()->with($user->id);
        });

        $this->actingAs($user)->postJson('/api/v1/planetary/refresh')->assertNoContent();
    }
}
