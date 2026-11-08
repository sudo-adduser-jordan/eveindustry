<?php

namespace Tests\Feature\ApiV1\Reference;

use App\Models\Character;
use App\Models\User;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Reference\SeedsReferenceData;
use Tests\TestCase;

class InventionMarketGroupsCategoriesAndCorporationsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blockOutsideHttp();
        $this->seedTypes();
        $this->seedBlueprints();
        $this->seedMarketGroups();
        $this->user = $this->makeUser();
    }

    public function test_invention_item_search_and_chain(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/invention/items?q=wol')
            ->assertOk()
            ->assertJsonPath('data.*.type_id', [11371])
            ->assertJsonPath('meta.limit', 20);

        $this->actingAs($this->user)->getJson('/api/v1/invention/items/11371')
            ->assertOk()
            ->assertJsonPath('data.t2_blueprint_type_id', 11372)
            ->assertJsonPath('data.t1_item_type_id', 587)
            ->assertJsonPath('data.t1_manufacturing.time', 6000)
            ->assertJsonPath('data.datacores.0.skill_name', 'Electromagnetic Physics')
            ->assertJsonPath('data.base_probability', 0.3)
            ->assertJsonPath('data.encryption_skill_id', 21791)
            ->assertJsonMissingPath('data.success');
    }

    public function test_manufacturing_categories(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/manufacturing/categories')
            ->assertOk()
            ->assertExactJson([
                'data' => [['category_id' => 6, 'name' => 'Ship', 'item_count' => 1]],
                'meta' => [],
            ]);
    }

    public function test_market_group_roots_and_tree(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/market-groups')
            ->assertOk()
            ->assertJsonPath('data.*.market_group_id', [2, 2000, 4])
            ->assertJsonPath('data.2', ['market_group_id' => 4, 'name' => 'Ships', 'description' => 'Ships group', 'has_types' => false, 'child_count' => 1]);

        $this->actingAs($this->user)->getJson('/api/v1/market-groups/tree')
            ->assertOk()
            ->assertJsonPath('data.0.market_group_id', 4)
            ->assertJsonPath('data.0.children.0.children.0', [
                'market_group_id' => 64, 'name' => 'Standard Frigates', 'has_types' => true, 'children' => [],
            ]);
    }

    public function test_market_group_contents(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/market-groups/64?exclude_meta_groups[]=1&exclude_meta_groups[]=4')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'market_group_id' => 64,
                    'name' => 'Standard Frigates',
                    'description' => 'Standard Frigates group',
                    'parent_group_id' => 1361,
                    'breadcrumbs' => [
                        ['market_group_id' => 4, 'name' => 'Ships'],
                        ['market_group_id' => 1361, 'name' => 'Frigates'],
                        ['market_group_id' => 64, 'name' => 'Standard Frigates'],
                    ],
                    'children' => [],
                    'items' => [
                        ['type_id' => 11371, 'name' => 'Wolf', 'market_group_id' => 64, 'icon_url' => 'https://images.evetech.net/types/11371/icon?size=32'],
                    ],
                ],
                'meta' => [],
            ]);

        $this->actingAs($this->user)->getJson('/api/v1/market-groups/64?exclude_meta_groups[]=x')
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_corporation_search(): void
    {
        Character::create([
            'character_id' => 90000001,
            'user_id' => $this->user->id,
            'name' => 'Main Char',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'is_main' => true,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
        ]);
        $this->mock(EsiService::class, function ($mock) {
            $mock->shouldReceive('searchCharacter')->andReturn(['corporation' => [98000001, 98000002]]);
            $mock->shouldReceive('getCorporation')->with(98000001)->andReturn(['name' => 'Goon Corp', 'ticker' => 'GOON']);
            $mock->shouldReceive('getCorporation')->with(98000002)->andReturn(null);
        });

        $this->actingAs($this->user)->getJson('/api/v1/corporations?q=goons')
            ->assertOk()
            ->assertExactJson([
                'data' => [['corporation_id' => 98000001, 'name' => 'Goon Corp', 'ticker' => 'GOON']],
                'meta' => ['limit' => 10],
            ]);
    }

    public function test_corporation_search_without_character_is_empty(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/corporations?q=goons')
            ->assertOk()
            ->assertExactJson(['data' => [], 'meta' => ['limit' => 10]]);
    }
}
