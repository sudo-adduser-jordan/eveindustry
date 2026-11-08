<?php

namespace Tests\Feature\ApiV1\Reference;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Reference\SeedsReferenceData;
use Tests\TestCase;

class TypesBlueprintsAndFormulasTest extends TestCase
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
        $this->user = $this->makeUser();
    }

    public function test_blueprint_search(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/blueprints?q=rif')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'type_id' => 691,
                    'name' => 'Rifter Blueprint',
                    'group_id' => 105,
                    'icon_url' => 'https://images.evetech.net/types/691/icon?size=32',
                ]],
                'meta' => ['limit' => 20],
            ]);
    }

    public function test_blueprint_activity(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/blueprints/691')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'type_id' => 691,
                    'activity' => 'manufacturing',
                    'time' => 6000,
                    'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 32000]],
                    'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
                ],
                'meta' => [],
            ]);
    }

    public function test_reaction_formula_search_and_activity(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/reactions/formulas?q=cadmide')
            ->assertOk()
            ->assertJsonPath('data.0.type_id', 46166)
            ->assertJsonPath('data.0.name', 'Caesarium Cadmide Reaction Formula')
            ->assertJsonPath('meta.limit', 20)
            ->assertJsonMissingPath('meta.pagination');

        $this->actingAs($this->user)->getJson('/api/v1/reactions/formulas/46166')
            ->assertOk()
            ->assertJsonPath('data.activity', 'reaction')
            ->assertJsonPath('data.time', 10800)
            ->assertJsonPath('data.products', [['type_id' => 16663, 'name' => 'Caesarium Cadmide', 'quantity' => 200]]);
    }

    public function test_type_show_from_sde(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/types/587')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);

        $this->assertEquals([
            'type_id' => 587,
            'name' => 'Rifter',
            'description' => 'Rifter description',
            'group_id' => 25,
            'market_group_id' => 64,
            'volume' => 27289.5,
            'packaged_volume' => 2500.5,
            'mass' => 1067000.5,
            'capacity' => 140.5,
            'portion_size' => 1,
            'icon_id' => 7,
            'graphic_id' => 8,
            'published' => true,
            'icon_url' => 'https://images.evetech.net/types/587/icon?size=64',
        ], $response->json('data'));
    }

    public function test_type_show_from_esi_fills_missing_fields_with_null(): void
    {
        $this->blockOutsideHttp([
            '*universe/types/555*' => ['type_id' => 555, 'name' => 'Esi Thing', 'group_id' => 9, 'published' => true],
        ]);

        $this->actingAs($this->user)->getJson('/api/v1/types/555')
            ->assertOk()
            ->assertJsonPath('data.name', 'Esi Thing')
            ->assertJsonPath('data.group_id', 9)
            ->assertJsonPath('data.market_group_id', null)
            ->assertJsonPath('data.volume', null);
    }

    public function test_type_search_with_meta_group_filter(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/types?q=rif')
            ->assertOk()
            ->assertJsonPath('meta.limit', 50)
            ->assertJsonPath('data.*.type_id', [587, 691, 11373])
            ->assertJsonPath('data.0', [
                'type_id' => 587,
                'name' => 'Rifter',
                'market_group_id' => 64,
                'icon_url' => 'https://images.evetech.net/types/587/icon?size=32',
            ]);

        $this->actingAs($this->user)->getJson('/api/v1/types?q=rif&exclude_meta_groups[]=4')
            ->assertOk()
            ->assertJsonPath('data.*.type_id', [587, 691]);

        $this->actingAs($this->user)->getJson('/api/v1/types?q=rif&exclude_meta_groups=1,4')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('exclude_meta_groups');
    }
}
