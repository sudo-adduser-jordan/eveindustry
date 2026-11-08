<?php

namespace Tests\Feature\ApiV1\Industry;

use App\Models\BuildProgram;
use App\Models\EveType;
use App\Models\IndustryStockItem;
use App\Models\User;
use App\Services\AssetService;
use App\Services\IndustryService;
use App\Services\MarketService;
use App\Services\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * The read endpoints behind the React dashboard, jobs overview, can produce,
 * stock manager and build program pages.
 */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::create(array_merge(['name' => 'Pilot', 'email' => 'p'.uniqid().'@test.com', 'is_active' => true], $attrs));
    }

    public function test_all_require_login(): void
    {
        foreach (['/dashboard', '/industry/jobs', '/industry/can-produce', '/stock', '/build-programs', '/build-programs/1'] as $path) {
            $this->getJson('/api/v1'.$path)->assertUnauthorized();
        }
    }

    public function test_jobs_overview_values_manufacturing_output_and_leaves_research_unpriced(): void
    {
        $user = $this->user();
        $job = fn (int $id, string $activity, int $typeId, string $name, float $output) => [
            'job_id' => $id, 'activity_id' => 1, 'activity_name' => $activity, 'character_name' => 'Ana', 'type_id' => $typeId,
            'item_name' => $name, 'item_image_url' => null, 'runs' => 2, 'probability_pct' => null, 'expected_output' => $output,
            'time_remaining' => '1h 0m', 'seconds_remaining' => 3600, 'end_date' => now()->addHour()->toIso8601String(),
            'progress_pct' => 50, 'ready_for_delivery' => false, 'location' => 'Jita',
        ];
        $industry = Mockery::mock(IndustryService::class)->makePartial();
        $industry->shouldReceive('getJobsOverviewForUser')->with($user->id)->andReturn([
            'Manufacturing' => [$job(1, 'Manufacturing', 587, 'Rifter', 4), $job(2, 'Manufacturing', 587, 'Rifter', 6)],
            'Copying' => [$job(3, 'Copying', 588, 'Slasher', 2)],
        ]);
        $this->app->instance(IndustryService::class, $industry);
        $market = Mockery::mock(MarketService::class);
        $market->shouldReceive('getSellPrices')->andReturn([587 => 1000.0]);
        $this->app->instance(MarketService::class, $market);

        $this->actingAs($user)->getJson('/api/v1/industry/jobs?region_id=10000043')
            ->assertOk()
            ->assertJsonPath('data.total_jobs', 3)
            ->assertJsonPath('data.groups.0.activity_name', 'Manufacturing')
            ->assertJsonPath('data.groups.0.jobs.0.estimated_value', 4000)
            ->assertJsonPath('data.groups.1.jobs.0.estimated_value', null)
            ->assertJsonPath('data.summary.0.item_name', 'Rifter')
            ->assertJsonPath('data.summary.0.total_expected_output', 10)
            ->assertJsonPath('data.summary.0.estimated_value', 10000)
            ->assertJsonPath('data.estimated_total', 10000)
            ->assertJsonPath('meta.region.region_id', 10000043);

        $this->actingAs($user)->getJson('/api/v1/industry/jobs?region_id=1')->assertStatus(422);
    }

    public function test_can_produce_reads_the_cache_or_computes(): void
    {
        $user = $this->user();
        $source = [
            'type' => 'character', 'character_id' => 1, 'character_name' => 'Ana', 'portrait_url' => 'p', 'corporation_name' => 'C',
            'blueprint_count' => 1, 'material_count' => 1,
            'buildable' => [[
                'blueprint_type_id' => 10, 'product_type_id' => 11, 'product_name' => 'Thing', 'product_qty_per_run' => 1, 'me' => 5, 'te' => 0,
                'max_runs' => -1, 'buildable_runs' => 3, 'total_output' => 3,
                'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'required_per_run' => 10, 'available' => 35, 'runs_possible' => 3]],
            ]],
            'available_materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 35]],
        ];
        $production = Mockery::mock(ProductionService::class);
        $production->shouldReceive('getCanProduceForUser')->once()->with($user->id)->andReturn([$source]);
        $this->app->instance(ProductionService::class, $production);

        foreach ([1, 2] as $_) {
            $this->actingAs($user)->getJson('/api/v1/industry/can-produce')
                ->assertOk()
                ->assertJsonPath('data.0.character_name', 'Ana')
                ->assertJsonPath('data.0.buildable.0.max_runs', -1)
                ->assertJsonPath('data.0.buildable.0.materials.0.runs_possible', 3)
                ->assertJsonPath('data.0.available_materials.0.quantity', 35);
        }
        $this->assertTrue(Cache::has("can_produce.{$user->id}"));
    }

    public function test_stock_report_is_for_stock_managers_only(): void
    {
        $plain = $this->user();
        $this->actingAs($plain)->getJson('/api/v1/stock')->assertForbidden();
        $this->actingAs($plain)->postJson('/api/v1/stock/refresh')->assertForbidden();

        $manager = $this->user(['is_stock_manager_enabled' => true]);
        EveType::create(['type_id' => 34, 'name' => 'Tritanium', 'group_id' => 18, 'published' => true]);
        IndustryStockItem::create(['user_id' => $manager->id, 'type_id' => 34, 'type_name' => 'Tritanium', 'desired_quantity' => 100]);
        $assets = Mockery::mock(AssetService::class)->makePartial();
        $assets->shouldReceive('getCorporationAssetsWarning')->andReturn(null);
        $assets->shouldReceive('getAssetsForUser')->andReturn([['owner_type' => 'corporate', 'type_id' => 34, 'quantity' => 40]]);
        $assets->shouldReceive('clearUserCache')->once()->with($manager->id);
        $this->app->instance(AssetService::class, $assets);

        $this->actingAs($manager)->getJson('/api/v1/stock')
            ->assertOk()
            ->assertJsonPath('data.0.type_name', 'Tritanium')
            ->assertJsonPath('data.0.desired_quantity', 100)
            ->assertJsonPath('data.0.on_hand', 40)
            ->assertJsonPath('data.0.shortfall', 60)
            ->assertJsonPath('meta.warning', null);
        $this->actingAs($manager)->postJson('/api/v1/stock/refresh')->assertNoContent();
    }

    public function test_build_programs_list_and_show_are_scoped_to_the_owner(): void
    {
        $user = $this->user();
        $other = $this->user();
        EveType::create(['type_id' => 34, 'name' => 'Tritanium', 'group_id' => 18, 'published' => true, 'volume' => 0.01, 'packaged_volume' => 0.01]);
        $mine = BuildProgram::create([
            'user_id' => $user->id, 'name' => 'Mine', 't2_type_id' => 11, 't2_type_name' => 'Thing',
            'steps' => [[
                'type_id' => 11, 'name' => 'Thing', 'quantity_required' => 2, 'runs_needed' => 2, 'activity_type' => 'manufacturing',
                'blueprint_type_id' => 10, 'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 500]],
                'children' => [],
            ]],
            'settings' => ['location_ids' => ['60003760'], 'sections' => [['name' => 'A', 'step_count' => 1]]],
        ]);
        $theirs = BuildProgram::create(['user_id' => $other->id, 'name' => 'Theirs', 'steps' => [], 'settings' => []]);

        $this->actingAs($user)->getJson('/api/v1/build-programs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mine')
            ->assertJsonPath('data.0.step_count', 1)
            ->assertJsonPath('data.0.location_ids', [60003760]);

        $this->actingAs($user)->getJson("/api/v1/build-programs/{$mine->id}")
            ->assertOk()
            ->assertJsonPath('data.steps.0.materials.0.quantity', 500)
            ->assertJsonPath('data.steps.0.activity_type', 'manufacturing')
            ->assertJsonPath('data.sections.0.name', 'A')
            ->assertJsonPath('data.location_ids', [60003760])
            ->assertJsonPath('meta.volumes.34', 0.01);

        $this->actingAs($user)->getJson("/api/v1/build-programs/{$theirs->id}")->assertNotFound();
    }

    public function test_dashboard_lists_characters_and_plans(): void
    {
        $user = $this->user();
        $user->characters()->create([
            'character_id' => 1, 'name' => 'Ana', 'corporation_id' => 5, 'corporation_name' => 'Corp', 'is_main' => true,
            'access_token' => 't', 'refresh_token' => 't', 'token_expires_at' => now()->addHour(), 'scopes' => [],
            'cached_location' => 'Jita', 'cached_training' => 'Mass Production V',
        ]);
        Cache::put("dashboard.industry_stats.{$user->id}", ['in_progress' => 1, 'ready_jobs' => 2, 'available_slots' => 3], 60);
        Cache::put("dashboard.pi_stats.{$user->id}", ['total_planets' => 4, 'expired_extractors' => 0, 'full_storage' => 1], 60);

        $this->actingAs($user)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.industry.ready_jobs', 2)
            ->assertJsonPath('data.planetary.total_planets', 4)
            ->assertJsonPath('data.characters.0.location', 'Jita')
            ->assertJsonPath('data.characters.0.training', 'Mass Production V')
            ->assertJsonPath('data.reaction_plans', []);
    }
}
