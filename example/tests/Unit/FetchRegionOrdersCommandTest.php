<?php

namespace Tests\Unit;

use App\Jobs\FetchRegionOrders as FetchRegionOrdersJob;
use App\Services\MarketTradingService;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FetchRegionOrdersCommandTest extends TestCase
{
    public function test_command_dispatches_jobs_for_all_regions(): void
    {
        Queue::fake();

        $this->artisan('market:fetch-region-orders')
            ->assertSuccessful();

        Queue::assertPushed(FetchRegionOrdersJob::class, count(MarketTradingService::REGIONS));
    }

    public function test_command_dispatches_job_for_single_region(): void
    {
        Queue::fake();

        $this->artisan('market:fetch-region-orders', ['--region' => '10000002'])
            ->assertSuccessful();

        Queue::assertPushed(FetchRegionOrdersJob::class, 1);
    }

    public function test_command_leaves_out_excluded_regions(): void
    {
        Queue::fake();

        $this->artisan('market:fetch-region-orders', ['--exclude' => ['10000002']])
            ->assertSuccessful();

        Queue::assertPushed(FetchRegionOrdersJob::class, count(MarketTradingService::REGIONS) - 1);
        Queue::assertNotPushed(FetchRegionOrdersJob::class, function (FetchRegionOrdersJob $job) {
            return (new \ReflectionProperty($job, 'regionId'))->getValue($job) === 10000002;
        });
        Queue::assertPushedOn('market', FetchRegionOrdersJob::class);
    }
}
