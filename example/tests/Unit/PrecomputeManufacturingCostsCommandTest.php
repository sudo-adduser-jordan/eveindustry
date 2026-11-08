<?php

namespace Tests\Unit;

use App\Jobs\PrecomputeManufacturingCostsChunk;
use App\Models\EveType;
use App\Services\ManufacturingBrowserService;
use App\Services\MarketTradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The command only dispatches one job per region with scalar options. Each job
 * loads and filters its own blueprints, so the filtering itself is covered in
 * PrecomputeManufacturingCostsJobTest.
 */
class PrecomputeManufacturingCostsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    private function jobProperty(PrecomputeManufacturingCostsChunk $job, string $name): mixed
    {
        return (new \ReflectionProperty($job, $name))->getValue($job);
    }

    public function test_command_dispatches_jobs_for_all_regions(): void
    {
        Queue::fake();

        $this->artisan('manufacturing:precompute-costs')
            ->assertSuccessful();

        // One job per region (each handles all ME levels internally)
        $expectedJobs = count(MarketTradingService::REGIONS);
        Queue::assertPushed(PrecomputeManufacturingCostsChunk::class, $expectedJobs);

        foreach (array_keys(MarketTradingService::REGIONS) as $regionId) {
            Queue::assertPushed(PrecomputeManufacturingCostsChunk::class, function ($job) use ($regionId) {
                return $this->jobProperty($job, 'regionId') === $regionId;
            });
        }
    }

    public function test_command_does_not_load_blueprints_itself(): void
    {
        Queue::fake();

        // Loading blueprints in the command would serialize the full item list
        // into every region's payload; the job does it instead.
        $mockBrowser = $this->mock(ManufacturingBrowserService::class);
        $mockBrowser->shouldNotReceive('getAllManufacturingBlueprints');

        $this->artisan('manufacturing:precompute-costs')
            ->assertSuccessful();

        Queue::assertPushed(PrecomputeManufacturingCostsChunk::class, count(MarketTradingService::REGIONS));
    }

    public function test_command_with_include_all_flag_skips_meta_group_filter(): void
    {
        Queue::fake();

        $this->artisan('manufacturing:precompute-costs', ['--include-all' => true])
            ->assertSuccessful();

        $expectedJobs = count(MarketTradingService::REGIONS);
        Queue::assertPushed(PrecomputeManufacturingCostsChunk::class, $expectedJobs);

        Queue::assertPushed(PrecomputeManufacturingCostsChunk::class, function ($job) {
            return $this->jobProperty($job, 'includeAll') === true;
        });
        Queue::assertNotPushed(PrecomputeManufacturingCostsChunk::class, function ($job) {
            return $this->jobProperty($job, 'includeAll') === false;
        });
    }

    public function test_command_default_filters_to_t1_only(): void
    {
        Queue::fake();

        $this->artisan('manufacturing:precompute-costs')
            ->assertSuccessful();

        $expectedJobs = count(MarketTradingService::REGIONS);
        Queue::assertPushed(PrecomputeManufacturingCostsChunk::class, $expectedJobs);

        // Every job is told to keep only T1 products
        Queue::assertNotPushed(PrecomputeManufacturingCostsChunk::class, function ($job) {
            return $this->jobProperty($job, 'includeAll') !== false
                || $this->jobProperty($job, 'allowedMetaGroups') !== [EveType::META_GROUP_TECH_I];
        });
    }

    public function test_command_include_t2_adds_tech_ii_meta_group(): void
    {
        Queue::fake();

        $this->artisan('manufacturing:precompute-costs', ['--include-t2' => true])
            ->assertSuccessful();

        Queue::assertPushed(PrecomputeManufacturingCostsChunk::class, function ($job) {
            return $this->jobProperty($job, 'allowedMetaGroups') === [EveType::META_GROUP_TECH_I, EveType::META_GROUP_TECH_II];
        });
    }
}
