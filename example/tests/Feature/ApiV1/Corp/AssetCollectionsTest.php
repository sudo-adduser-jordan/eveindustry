<?php

namespace Tests\Feature\ApiV1\Corp;

use App\Models\EveType;
use App\Models\InvGroup;
use App\Models\MarketPrice;
use App\Services\AbyssalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Corp\CorpFixtures;
use Tests\TestCase;

/**
 * The materials, ships and abyssal views of the user's assets.
 */
class AssetCollectionsTest extends TestCase
{
    use CorpFixtures, RefreshDatabase;

    public function test_requires_auth(): void
    {
        foreach (['materials', 'ships', 'abyssal'] as $page) {
            $this->getJson("/api/v1/assets/{$page}")->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_materials_are_grouped_summed_and_priced(): void
    {
        EveType::create(['type_id' => 34, 'name' => 'Tritanium', 'group_id' => 18, 'published' => true]);
        EveType::create(['type_id' => 35, 'name' => 'Pyerite', 'group_id' => 18, 'published' => true]);
        MarketPrice::create(['type_id' => 34, 'average_price' => 5.0, 'adjusted_price' => 5.0]);

        $user = $this->makeUser();
        $this->makeDirector($user);
        $this->seedAssetCache($user->id, [
            $this->asset(['item_id' => 1, 'type_id' => 34, 'type_name' => 'Tritanium', 'quantity' => 1000]),
            $this->asset(['item_id' => 2, 'type_id' => 34, 'type_name' => 'Tritanium', 'quantity' => 500, 'location_id' => 60008494]),
            $this->asset(['item_id' => 3, 'type_id' => 35, 'type_name' => 'Pyerite', 'quantity' => 7]),
            $this->asset(['item_id' => 4, 'type_id' => 587, 'type_name' => 'Rifter']),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/assets/materials');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['grand_total', 'groups' => [['name', 'total', 'items' => [['type_id', 'type_name', 'quantity', 'unit_price', 'total_value']]]]],
                'meta' => ['last_updated', 'corporation_assets_warning'],
            ])
            ->assertJsonPath('meta.last_updated', date('c', 1792584000))
            ->assertJsonCount(1, 'data.groups')
            ->assertJsonPath('data.groups.0.name', 'Minerals')
            ->assertJsonPath('data.groups.0.items.*.type_name', ['Pyerite', 'Tritanium'])
            ->assertJsonPath('data.groups.0.items.1.quantity', 1500);
    }

    public function test_materials_explain_missing_corporation_assets(): void
    {
        $user = $this->makeUser();
        $this->seedAssetCache($user->id, []);

        $this->actingAs($user)->getJson('/api/v1/assets/materials')
            ->assertOk()
            ->assertJsonPath('data.groups', [])
            ->assertJsonPath('data.grand_total', 0)
            ->assertJsonPath('meta.corporation_assets_warning', fn ($w) => str_contains($w, 'No main character'));
    }

    public function test_ships_are_grouped_by_ship_group(): void
    {
        InvGroup::create(['group_id' => 25, 'name' => 'Frigate', 'category_id' => 6, 'published' => true]);
        InvGroup::create(['group_id' => 26, 'name' => 'Cruiser', 'category_id' => 6, 'published' => true]);
        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'group_id' => 25, 'published' => true]);
        EveType::create(['type_id' => 622, 'name' => 'Stabber', 'group_id' => 26, 'published' => true]);
        EveType::create(['type_id' => 34, 'name' => 'Tritanium', 'group_id' => 18, 'published' => true]);

        $user = $this->makeUser();
        $this->seedAssetCache($user->id, [
            $this->asset(['item_id' => 1, 'type_id' => 587, 'type_name' => 'Rifter', 'name' => 'My Rifter', 'is_singleton' => true]),
            $this->asset(['item_id' => 2, 'type_id' => 587, 'type_name' => 'Rifter', 'name' => null, 'is_singleton' => true]),
            $this->asset(['item_id' => 3, 'type_id' => 622, 'type_name' => 'Stabber', 'is_singleton' => true]),
            $this->asset(['item_id' => 4, 'type_id' => 34, 'type_name' => 'Tritanium']),
        ]);

        $this->actingAs($user)->getJson('/api/v1/assets/ships')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['total_ships', 'type_count', 'ship_groups' => [['group_name', 'count', 'ships' => [['item_id', 'type_id', 'type_name', 'name', 'location_name', 'character_id', 'character_name']]]]],
                'meta' => ['last_updated'],
            ])
            ->assertJsonPath('data.total_ships', 3)
            ->assertJsonPath('data.type_count', 2)
            ->assertJsonPath('data.ship_groups.*.group_name', ['Cruiser', 'Frigate'])
            ->assertJsonPath('data.ship_groups.1.count', 2)
            ->assertJsonPath('data.ship_groups.1.ships.*.item_id', [2, 1]);
    }

    public function test_abyssal_items_come_with_display_strings(): void
    {
        $this->mock(AbyssalService::class, function ($mock) {
            $mock->shouldReceive('getAbyssalItemsForUser')->once()->andReturn([[
                'source_type_id' => 3841,
                'source_type_name' => 'Small Shield Booster II',
                'items' => [[
                    'item_id' => 11,
                    'type_id' => 47000,
                    'type_name' => 'Unstable Small Shield Booster',
                    'character_name' => 'Main Pilot',
                    'character_id' => 9001,
                    'location_name' => 'Jita IV',
                    'container_name' => null,
                    'mutator_type_id' => 47700,
                    'score' => 12.34,
                    'attributes' => [[
                        'attribute_id' => 73,
                        'name' => 'Activation time',
                        'unit_id' => 115,
                        'icon_id' => null,
                        'value' => 3500.0,
                        'base_value' => 4000.0,
                        'pct_change' => -12.5,
                        'high_is_good' => false,
                        'is_good' => true,
                    ]],
                ]],
            ]]);
        });
        $user = $this->makeUser();
        $this->seedAssetCache($user->id, []);

        $this->actingAs($user)->getJson('/api/v1/assets/abyssal')
            ->assertOk()
            ->assertJsonPath('data.0.source_type_name', 'Small Shield Booster II')
            ->assertJsonPath('data.0.items.0.score', 12.3)
            ->assertJsonPath('data.0.items.0.container_name', null)
            ->assertJsonPath('data.0.items.0.mutator_type_id', 47700)
            ->assertJsonPath('data.0.items.0.attributes.0.value', '3.50s')
            ->assertJsonPath('data.0.items.0.attributes.0.change', '-0.50s')
            ->assertJsonPath('data.0.items.0.attributes.0.pct_change', -12.5)
            ->assertJsonPath('data.0.items.0.attributes.0.is_good', true)
            ->assertJsonMissingPath('data.0.items.0.attributes.0.unit_id')
            ->assertJsonPath('meta.last_updated', date('c', 1792584000));
    }
}
