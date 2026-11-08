<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ReactionPlannerStockTest extends TestCase
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

    public function test_stock_returns_aggregated_quantities(): void
    {
        $character = Character::create([
            'character_id' => 12345,
            'user_id' => $this->user->id,
            'name' => 'Test Character',
            'corporation_id' => 98000001,
            'scopes' => ['esi-assets.read_assets.v1'],
        ]);

        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterAssets')
            ->with(Mockery::on(fn ($c) => $c->character_id === 12345))
            ->andReturn([
                ['type_id' => 16634, 'quantity' => 5000],
                ['type_id' => 16635, 'quantity' => 3000],
                ['type_id' => 16634, 'quantity' => 2000], // duplicate type, different stack
                ['type_id' => 99999, 'quantity' => 100],  // not requested
            ]);
        $this->app->instance(EsiService::class, $esi);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/calculators/reaction-planner/stock', [
                'type_ids' => [16634, 16635, 16636],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.0.quantity', 7000)
            ->assertJsonPath('data.1.quantity', 3000);

        // 16636 is not in the assets: still listed, with 0
        $this->assertSame(0, $response->json('data.2.quantity'));
        // 99999 was not requested, so it is not listed
        $this->assertCount(3, $response->json('data'));
    }

    public function test_stock_skips_characters_without_asset_scope(): void
    {
        Character::create([
            'character_id' => 12345,
            'user_id' => $this->user->id,
            'name' => 'No Scope Char',
            'corporation_id' => 98000001,
            'scopes' => [],
        ]);

        $esi = Mockery::mock(EsiService::class);
        $esi->shouldNotReceive('getCharacterAssets');
        $this->app->instance(EsiService::class, $esi);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/calculators/reaction-planner/stock', [
                'type_ids' => [16634],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.0.quantity', 0);
    }

    public function test_stock_aggregates_across_multiple_characters(): void
    {
        Character::create([
            'character_id' => 11111,
            'user_id' => $this->user->id,
            'name' => 'Char One',
            'corporation_id' => 98000001,
            'scopes' => ['esi-assets.read_assets.v1'],
        ]);

        Character::create([
            'character_id' => 22222,
            'user_id' => $this->user->id,
            'name' => 'Char Two',
            'corporation_id' => 98000001,
            'scopes' => ['esi-assets.read_assets.v1'],
        ]);

        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterAssets')
            ->with(Mockery::on(fn ($c) => $c->character_id === 11111))
            ->andReturn([
                ['type_id' => 16634, 'quantity' => 1000],
            ]);
        $esi->shouldReceive('getCharacterAssets')
            ->with(Mockery::on(fn ($c) => $c->character_id === 22222))
            ->andReturn([
                ['type_id' => 16634, 'quantity' => 2500],
            ]);
        $this->app->instance(EsiService::class, $esi);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/calculators/reaction-planner/stock', [
                'type_ids' => [16634],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.0.quantity', 3500);
    }

    public function test_stock_validates_type_ids_required(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/calculators/reaction-planner/stock', []);

        $response->assertUnprocessable();
    }

    public function test_stock_unauthenticated(): void
    {
        $response = $this->postJson('/api/v1/calculators/reaction-planner/stock', [
            'type_ids' => [16634],
        ]);

        $response->assertUnauthorized();
    }
}
