<?php

namespace Tests\Unit;

use App\Jobs\ImportDailyMarketHistory as ImportDailyMarketHistoryJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportDailyMarketHistoryCommandTest extends TestCase
{
    public function test_command_dispatches_jobs_for_today_and_yesterday(): void
    {
        Queue::fake();

        $this->artisan('market:import-daily-history')
            ->assertSuccessful();

        Queue::assertPushed(ImportDailyMarketHistoryJob::class, 2);
    }

    public function test_command_dispatches_job_for_specific_date(): void
    {
        Queue::fake();

        $this->artisan('market:import-daily-history', ['--date' => '2026-02-22'])
            ->assertSuccessful();

        Queue::assertPushed(ImportDailyMarketHistoryJob::class, 1);
        Queue::assertPushed(ImportDailyMarketHistoryJob::class, function ($job) {
            return $job->getDate()->toDateString() === '2026-02-22';
        });
    }
}
