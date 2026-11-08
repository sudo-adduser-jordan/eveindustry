<?php

namespace Tests\Feature;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Models\InvGroup;
use App\Models\User;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ReactionFormulaResearchTest extends TestCase
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

    private function seedReactionFormula(int $typeId, string $name, int $groupId = 1888): void
    {
        EveType::create([
            'type_id' => $typeId,
            'name' => $name,
            'group_id' => $groupId,
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => $typeId,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'quantity' => 100]],
            'products' => [['type_id' => $typeId + 10000, 'quantity' => 200]],
        ]);
    }

    private function seedGroup(int $groupId, string $name): void
    {
        InvGroup::firstOrCreate(
            ['group_id' => $groupId],
            ['name' => $name, 'category_id' => 18, 'published' => true]
        );
    }

    public function test_reaction_formula_research_export_requires_auth(): void
    {
        $response = $this->get(route('industry.reaction-formula-research.export'));
        $response->assertRedirect(route('login'));
    }

    public function test_reaction_formula_research_export_returns_csv(): void
    {
        $this->seedGroup(1888, 'Composite Reaction Formulas');
        $this->seedReactionFormula(46206, 'Titanium Carbide Reaction Formula', 1888);

        $response = $this->actingAs($this->user)
            ->get(route('industry.reaction-formula-research.export'));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=utf-8');
        $response->assertDownload();
    }

    public function test_reaction_formula_research_export_contains_formula_data(): void
    {
        $this->seedGroup(1888, 'Composite Reaction Formulas');
        $this->seedReactionFormula(46206, 'Titanium Carbide Reaction Formula', 1888);
        $this->seedReactionFormula(46207, 'Vanadium Hafnite Reaction Formula', 1888);

        $response = $this->actingAs($this->user)
            ->get(route('industry.reaction-formula-research.export'));

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Formula Name', $csv);
        $this->assertStringContainsString('Titanium Carbide Reaction Formula', $csv);
        $this->assertStringContainsString('Vanadium Hafnite Reaction Formula', $csv);
        $this->assertStringContainsString('Composite', $csv);
    }

    public function test_reaction_formula_research_export_includes_ownership_data(): void
    {
        $this->seedGroup(1888, 'Composite Reaction Formulas');
        $this->seedReactionFormula(46206, 'Titanium Carbide Reaction Formula', 1888);

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
                ['type_id' => 46206, 'quantity' => 3, 'material_efficiency' => 0, 'time_efficiency' => 0],
            ]);
        $this->app->instance(EsiService::class, $esiMock);

        $response = $this->actingAs($this->user)
            ->get(route('industry.reaction-formula-research.export'));

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Quantity', $csv);
        $this->assertStringContainsString('Owners', $csv);
        $this->assertStringContainsString('Test Char', $csv);
        $this->assertStringContainsString('3', $csv);
    }

    public function test_reaction_formula_research_does_not_include_non_reaction_blueprints(): void
    {
        $this->seedGroup(1888, 'Composite Reaction Formulas');
        $this->seedReactionFormula(46206, 'Titanium Carbide Reaction Formula', 1888);

        // Create a non-reaction blueprint (manufacturing)
        EveType::create([
            'type_id' => 950,
            'name' => 'Rifter Blueprint',
            'group_id' => 25,
            'published' => true,
        ]);
        BlueprintActivity::create([
            'blueprint_type_id' => 950,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 600,
            'materials' => [['type_id' => 34, 'quantity' => 100]],
            'products' => [['type_id' => 587, 'quantity' => 1]],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/industry/reaction-formula-research');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Titanium Carbide Reaction Formula');
    }

    public function test_formula_in_active_reaction_job_is_counted_as_owned(): void
    {
        $this->seedGroup(1888, 'Composite Reaction Formulas');
        $this->seedReactionFormula(46206, 'Titanium Carbide Reaction Formula', 1888);

        $this->user->characters()->create([
            'character_id' => 12345,
            'corporation_id' => 98000001,
            'name' => 'Test Char',
            'access_token' => 'test',
            'refresh_token' => 'test',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-characters.read_blueprints.v1', 'esi-industry.read_character_jobs.v1'],
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        // Blueprint list is empty (formula is locked in a job)
        $esiMock->shouldReceive('getCharacterBlueprints')
            ->with(Mockery::on(fn ($c) => $c->character_id === 12345))
            ->andReturn([]);
        // Active reaction job using the formula
        $esiMock->shouldReceive('getCharacterIndustryJobs')
            ->with(Mockery::on(fn ($c) => $c->character_id === 12345))
            ->andReturn([
                [
                    'job_id' => 1001,
                    'activity_id' => 9, // reactions
                    'blueprint_type_id' => 46206,
                    'status' => 'active',
                ],
            ]);
        $this->app->instance(EsiService::class, $esiMock);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/industry/reaction-formula-research');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.owned', true);
        $response->assertJsonPath('data.0.quantity', 1);
    }

    public function test_formula_in_ready_reaction_job_is_counted_as_owned(): void
    {
        $this->seedGroup(1888, 'Composite Reaction Formulas');
        $this->seedReactionFormula(46206, 'Titanium Carbide Reaction Formula', 1888);

        $this->user->characters()->create([
            'character_id' => 12345,
            'corporation_id' => 98000001,
            'name' => 'Test Char',
            'access_token' => 'test',
            'refresh_token' => 'test',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-characters.read_blueprints.v1', 'esi-industry.read_character_jobs.v1'],
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterBlueprints')
            ->with(Mockery::on(fn ($c) => $c->character_id === 12345))
            ->andReturn([]);
        $esiMock->shouldReceive('getCharacterIndustryJobs')
            ->with(Mockery::on(fn ($c) => $c->character_id === 12345))
            ->andReturn([
                [
                    'job_id' => 1002,
                    'activity_id' => 9, // reactions
                    'blueprint_type_id' => 46206,
                    'status' => 'ready',
                ],
            ]);
        $this->app->instance(EsiService::class, $esiMock);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/industry/reaction-formula-research');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.owned', true);
        $response->assertJsonPath('data.0.quantity', 1);
    }

    public function test_delivered_reaction_job_does_not_add_to_owned_count(): void
    {
        $this->seedGroup(1888, 'Composite Reaction Formulas');
        $this->seedReactionFormula(46206, 'Titanium Carbide Reaction Formula', 1888);

        $this->user->characters()->create([
            'character_id' => 12345,
            'corporation_id' => 98000001,
            'name' => 'Test Char',
            'access_token' => 'test',
            'refresh_token' => 'test',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-characters.read_blueprints.v1', 'esi-industry.read_character_jobs.v1'],
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterBlueprints')
            ->with(Mockery::on(fn ($c) => $c->character_id === 12345))
            ->andReturn([]);
        $esiMock->shouldReceive('getCharacterIndustryJobs')
            ->with(Mockery::on(fn ($c) => $c->character_id === 12345))
            ->andReturn([
                [
                    'job_id' => 1003,
                    'activity_id' => 9,
                    'blueprint_type_id' => 46206,
                    'status' => 'delivered',
                ],
            ]);
        $this->app->instance(EsiService::class, $esiMock);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/industry/reaction-formula-research');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.owned', false);
    }

    public function test_non_reaction_active_job_does_not_add_to_owned_count(): void
    {
        $this->seedGroup(1888, 'Composite Reaction Formulas');
        $this->seedReactionFormula(46206, 'Titanium Carbide Reaction Formula', 1888);

        $this->user->characters()->create([
            'character_id' => 12345,
            'corporation_id' => 98000001,
            'name' => 'Test Char',
            'access_token' => 'test',
            'refresh_token' => 'test',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-characters.read_blueprints.v1', 'esi-industry.read_character_jobs.v1'],
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterBlueprints')
            ->with(Mockery::on(fn ($c) => $c->character_id === 12345))
            ->andReturn([]);
        // Manufacturing job (activity_id 1), not a reaction
        $esiMock->shouldReceive('getCharacterIndustryJobs')
            ->with(Mockery::on(fn ($c) => $c->character_id === 12345))
            ->andReturn([
                [
                    'job_id' => 1004,
                    'activity_id' => 1, // manufacturing
                    'blueprint_type_id' => 46206,
                    'status' => 'active',
                ],
            ]);
        $this->app->instance(EsiService::class, $esiMock);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/industry/reaction-formula-research');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.owned', false);
    }

    public function test_active_job_combined_with_inventory_adds_to_total(): void
    {
        $this->seedGroup(1888, 'Composite Reaction Formulas');
        $this->seedReactionFormula(46206, 'Titanium Carbide Reaction Formula', 1888);

        $this->user->characters()->create([
            'character_id' => 12345,
            'corporation_id' => 98000001,
            'name' => 'Test Char',
            'access_token' => 'test',
            'refresh_token' => 'test',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-characters.read_blueprints.v1', 'esi-industry.read_character_jobs.v1'],
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        // 2 formulas in blueprint list
        $esiMock->shouldReceive('getCharacterBlueprints')
            ->with(Mockery::on(fn ($c) => $c->character_id === 12345))
            ->andReturn([
                ['type_id' => 46206, 'quantity' => 2, 'material_efficiency' => 0, 'time_efficiency' => 0],
            ]);
        // 1 formula in active job
        $esiMock->shouldReceive('getCharacterIndustryJobs')
            ->with(Mockery::on(fn ($c) => $c->character_id === 12345))
            ->andReturn([
                [
                    'job_id' => 1005,
                    'activity_id' => 9,
                    'blueprint_type_id' => 46206,
                    'status' => 'active',
                ],
            ]);
        $this->app->instance(EsiService::class, $esiMock);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/industry/reaction-formula-research');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.quantity', 3);
    }

    public function test_formulas_in_jobs_skipped_without_industry_scope(): void
    {
        $this->seedGroup(1888, 'Composite Reaction Formulas');
        $this->seedReactionFormula(46206, 'Titanium Carbide Reaction Formula', 1888);

        $this->user->characters()->create([
            'character_id' => 12345,
            'corporation_id' => 98000001,
            'name' => 'Test Char',
            'access_token' => 'test',
            'refresh_token' => 'test',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-characters.read_blueprints.v1'], // no industry scope
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterBlueprints')
            ->with(Mockery::on(fn ($c) => $c->character_id === 12345))
            ->andReturn([]);
        // getCharacterIndustryJobs should NOT be called
        $esiMock->shouldNotReceive('getCharacterIndustryJobs');
        $this->app->instance(EsiService::class, $esiMock);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/industry/reaction-formula-research');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.owned', false);
    }
}
