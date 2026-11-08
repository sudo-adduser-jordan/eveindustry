<?php

namespace Tests\Feature;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Models\InvCategory;
use App\Models\InvGroup;
use App\Models\MarketGroup;
use App\Models\User;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BlueprintResearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
    }

    private function seedBlueprint(int $typeId, string $name, int $groupId, ?int $marketGroupId = null): void
    {
        EveType::create([
            'type_id' => $typeId,
            'name' => $name,
            'group_id' => $groupId,
            'market_group_id' => $marketGroupId,
            'meta_group_id' => null,
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => $typeId,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 600,
            'materials' => [['type_id' => 34, 'quantity' => 100]],
            'products' => [['type_id' => $typeId + 10000, 'quantity' => 1]],
        ]);
    }

    private function seedCategoryAndGroup(int $categoryId, string $categoryName, int $groupId, string $groupName): void
    {
        InvCategory::firstOrCreate(
            ['category_id' => $categoryId],
            ['name' => $categoryName, 'published' => true]
        );

        InvGroup::firstOrCreate(
            ['group_id' => $groupId],
            ['name' => $groupName, 'category_id' => $categoryId, 'published' => true]
        );
    }

    private function seedMarketGroup(int $marketGroupId, string $name): void
    {
        MarketGroup::firstOrCreate(
            ['market_group_id' => $marketGroupId],
            ['name' => $name, 'has_types' => true]
        );
    }

    public function test_blueprint_research_export_csv_requires_auth(): void
    {
        $response = $this->get(route('industry.blueprint-research.export'));
        $response->assertRedirect(route('login'));
    }

    public function test_blueprint_research_export_csv_returns_csv_response(): void
    {
        $this->seedCategoryAndGroup(6, 'Ship', 25, 'Frigate');
        $this->seedMarketGroup(100, 'Frigate Blueprints');
        $this->seedBlueprint(950, 'Rifter Blueprint', 25, 100);

        $response = $this->actingAs($this->user)
            ->get(route('industry.blueprint-research.export'));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=utf-8');
        $response->assertDownload();
    }

    public function test_blueprint_research_export_csv_contains_blueprint_data(): void
    {
        $this->seedCategoryAndGroup(6, 'Ship', 25, 'Frigate');
        $this->seedMarketGroup(100, 'Frigate Blueprints');
        $this->seedBlueprint(950, 'Rifter Blueprint', 25, 100);
        $this->seedBlueprint(951, 'Punisher Blueprint', 25, 100);

        $response = $this->actingAs($this->user)
            ->get(route('industry.blueprint-research.export'));

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Blueprint Name', $csv);
        $this->assertStringContainsString('Rifter Blueprint', $csv);
        $this->assertStringContainsString('Punisher Blueprint', $csv);
        $this->assertStringContainsString('Frigate Blueprints', $csv);
    }

    public function test_blueprint_research_export_csv_includes_ownership_data(): void
    {
        $this->seedCategoryAndGroup(6, 'Ship', 25, 'Frigate');
        $this->seedMarketGroup(100, 'Frigate Blueprints');
        $this->seedBlueprint(950, 'Rifter Blueprint', 25, 100);

        $this->user->characters()->create([
            'character_id' => 12345,
            'corporation_id' => 98000001,
            'name' => 'Test Char',
            'access_token' => 'test',
            'refresh_token' => 'test',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-characters.read_blueprints.v1'],
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterBlueprints')
            ->with(Mockery::on(fn ($c) => $c->character_id === 12345))
            ->andReturn([
                ['type_id' => 950, 'material_efficiency' => 10, 'time_efficiency' => 20],
            ]);
        $this->app->instance(EsiService::class, $esiMock);

        $response = $this->actingAs($this->user)
            ->get(route('industry.blueprint-research.export'));

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $this->assertStringContainsString('ME%', $csv);
        $this->assertStringContainsString('TE%', $csv);
        $this->assertStringContainsString('Owners', $csv);
        $this->assertStringContainsString('Test Char', $csv);
    }

    public function test_blueprint_research_export_csv_respects_search_filter(): void
    {
        $this->seedCategoryAndGroup(6, 'Ship', 25, 'Frigate');
        $this->seedMarketGroup(100, 'Frigate Blueprints');
        $this->seedBlueprint(950, 'Rifter Blueprint', 25, 100);
        $this->seedBlueprint(951, 'Punisher Blueprint', 25, 100);

        $response = $this->actingAs($this->user)
            ->get(route('industry.blueprint-research.export', ['search' => 'Rifter']));

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Rifter Blueprint', $csv);
        $this->assertStringNotContainsString('Punisher Blueprint', $csv);
    }
}
