<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneFailedJobsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_is_disabled_by_default(): void
    {
        $this->insertFailure(now()->subYear());

        $this->artisan('queue:prune-failed')->assertSuccessful();

        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_opted_in_retention_deletes_only_older_records_in_batches(): void
    {
        $oldId = $this->insertFailure(now()->subDays(91));
        $recentId = $this->insertFailure(now()->subDays(89));
        config(['failed_jobs.retention.enabled' => true, 'failed_jobs.retention.days' => 90, 'failed_jobs.retention.batch_size' => 1]);

        $this->artisan('queue:prune-failed')->assertSuccessful();

        $this->assertDatabaseMissing('failed_jobs', ['id' => $oldId]);
        $this->assertDatabaseHas('failed_jobs', ['id' => $recentId]);
    }

    private function insertFailure($failedAt): int
    {
        return (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'updates',
            'payload' => '{}',
            'exception' => 'diagnostic sample',
            'failed_at' => $failedAt,
        ]);
    }
}
