<?php

namespace Tests\Feature\ApiV1\Industry;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Models\InvGroup;
use App\Models\MarketGroup;
use App\Models\User;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ResearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['name' => 'T', 'email' => 't@example.com', 'password' => bcrypt('x'), 'is_active' => true]);
    }

    private function seedBlueprint(int $typeId, string $name, int $marketGroupId, ?int $metaGroup = null): void
    {
        MarketGroup::firstOrCreate(['market_group_id' => $marketGroupId], ['name' => "Group {$marketGroupId}", 'has_types' => true]);
        EveType::create([
            'type_id' => $typeId, 'name' => $name, 'group_id' => 25, 'market_group_id' => $marketGroupId,
            'meta_group_id' => $metaGroup, 'published' => true,
        ]);
        BlueprintActivity::create([
            'blueprint_type_id' => $typeId, 'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING, 'time' => 600,
            'materials' => [['type_id' => 34, 'quantity' => 100]], 'products' => [['type_id' => $typeId + 10000, 'quantity' => 1]],
        ]);
    }

    private function seedFormula(int $typeId, string $name, int $groupId = 1888): void
    {
        InvGroup::firstOrCreate(['group_id' => $groupId], ['name' => "G{$groupId}", 'category_id' => 18, 'published' => true]);
        EveType::create(['type_id' => $typeId, 'name' => $name, 'group_id' => $groupId, 'published' => true]);
        BlueprintActivity::create([
            'blueprint_type_id' => $typeId, 'activity_id' => BlueprintActivity::ACTIVITY_REACTION, 'time' => 3600,
            'materials' => [['type_id' => 34, 'quantity' => 100]], 'products' => [['type_id' => $typeId + 10000, 'quantity' => 200]],
        ]);
    }

    private function character(int $id, string $name, array $scopes): void
    {
        $this->user->characters()->create([
            'character_id' => $id, 'corporation_id' => 98000001, 'name' => $name, 'access_token' => 't',
            'refresh_token' => 't', 'token_expires_at' => now()->addHour(), 'scopes' => $scopes,
        ]);
    }

    private function blueprints(array $byCharacter): void
    {
        $esi = Mockery::mock(EsiService::class);
        foreach ($byCharacter as $characterId => $rows) {
            $esi->shouldReceive('getCharacterBlueprints')->with(Mockery::on(fn ($c) => $c->character_id === $characterId))->andReturn($rows);
        }
        $this->app->instance(EsiService::class, $esi);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/industry/blueprint-research')->assertUnauthorized();
        $this->getJson('/api/v1/industry/reaction-formula-research')->assertUnauthorized();
    }

    public function test_blueprints_list_only_t1_and_filter(): void
    {
        $this->seedBlueprint(950, 'Rifter Blueprint', 100);
        $this->seedBlueprint(951, 'Laser Blueprint', 200);
        $this->seedBlueprint(960, 'Wolf Blueprint', 100, EveType::META_GROUP_TECH_II);

        $this->actingAs($this->user)->getJson('/api/v1/industry/blueprint-research')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Laser Blueprint')
            ->assertJsonPath('data.0.owned', false)
            ->assertJsonPath('meta.has_blueprint_data', false)
            ->assertJsonPath('meta.pagination.total', 2);

        $this->actingAs($this->user)->getJson('/api/v1/industry/blueprint-research?search=Rift')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Rifter Blueprint');
        $this->actingAs($this->user)->getJson('/api/v1/industry/blueprint-research?market_group=200')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Laser Blueprint');
        $this->actingAs($this->user)->getJson('/api/v1/industry/blueprint-research?sort=name&dir=desc')
            ->assertJsonPath('data.0.name', 'Rifter Blueprint');
        $this->actingAs($this->user)->getJson('/api/v1/industry/blueprint-research?per_page=1&page=2')
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.last_page', 2);
        $this->actingAs($this->user)->getJson('/api/v1/industry/blueprint-research?sort=bogus')->assertStatus(422);
    }

    public function test_blueprints_ownership_takes_best_me_te_and_lists_owners(): void
    {
        $this->seedBlueprint(950, 'Rifter Blueprint', 100);
        $this->character(1, 'Alpha', ['esi-characters.read_blueprints.v1']);
        $this->character(2, 'Beta', ['esi-characters.read_blueprints.v1']);
        $this->character(3, 'Gamma', []);
        $this->blueprints([
            1 => [['type_id' => 950, 'material_efficiency' => 8, 'time_efficiency' => 16]],
            2 => [['type_id' => 950, 'material_efficiency' => 10, 'time_efficiency' => 20]],
        ]);

        $this->actingAs($this->user)->getJson('/api/v1/industry/blueprint-research')
            ->assertOk()
            ->assertJsonPath('data.0.owned', true)
            ->assertJsonPath('data.0.me', 10)
            ->assertJsonPath('data.0.te', 20)
            ->assertJsonPath('data.0.owner_names', ['Alpha', 'Beta'])
            ->assertJsonPath('meta.has_blueprint_data', true)
            ->assertJsonPath('meta.characters_without_scope.0.name', 'Gamma');
    }

    public function test_formulas_sum_quantities_and_filter_by_category(): void
    {
        $this->seedFormula(46206, 'Titanium Carbide Reaction Formula');
        $this->seedFormula(46207, 'Hybrid Formula', 4097);
        $this->character(1, 'Alpha', ['esi-characters.read_blueprints.v1']);
        $this->character(2, 'Beta', ['esi-characters.read_blueprints.v1']);
        $this->blueprints([
            1 => [['type_id' => 46206, 'quantity' => 2]],
            2 => [['type_id' => 46206, 'quantity' => 5]],
        ]);

        $this->actingAs($this->user)->getJson('/api/v1/industry/reaction-formula-research')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Hybrid Formula')
            ->assertJsonPath('data.1.quantity', 7)
            ->assertJsonPath('data.1.category', 'Composite')
            ->assertJsonPath('meta.categories.0.name', 'Composite');

        $this->actingAs($this->user)->getJson('/api/v1/industry/reaction-formula-research?category=4097')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.category', 'Hybrid');
    }
}
