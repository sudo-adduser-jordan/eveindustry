<?php

namespace Tests\Unit;

use App\Jobs\RefreshIndustryData;
use App\Models\Character;
use App\Models\User;
use App\Services\IndustryService;
use App\Services\RefreshEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RefreshIndustryDataJobTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'is_active' => true, 'last_login_at' => now()]);
        Character::create([
            'character_id' => 12345, 'user_id' => $user->id, 'name' => 'Pilot', 'corporation_id' => 98,
            'is_esi_active' => true, 'scopes' => RefreshEligibility::INDUSTRY_SCOPES,
        ]);

        return $user;
    }

    public function test_job_builds_replacement_without_clearing_previous_cache(): void
    {
        $user = $this->createUser();
        $industry = Mockery::mock(IndustryService::class);
        $industry->shouldNotReceive('clearUserCache');
        $industry->shouldReceive('refreshForUser')->with($user->id)->once();
        (new RefreshIndustryData($user->id))->handle($industry);
    }

    public function test_job_propagates_failure_to_queue_retry_handler(): void
    {
        $user = $this->createUser();
        $industry = Mockery::mock(IndustryService::class);
        $industry->shouldReceive('refreshForUser')->once()->andThrow(new \RuntimeException('ESI unavailable'));
        $this->expectException(\RuntimeException::class);
        (new RefreshIndustryData($user->id))->handle($industry);
    }

    public function test_job_rechecks_eligibility_after_dispatch(): void
    {
        $user = $this->createUser();
        Character::query()->update(['is_esi_active' => false]);
        $industry = Mockery::mock(IndustryService::class);
        $industry->shouldNotReceive('refreshForUser');
        (new RefreshIndustryData($user->id))->handle($industry);
    }

    public function test_upstream_cooldown_releases_the_job_until_its_reset(): void
    {
        $user = $this->createUser();
        $industry = Mockery::mock(IndustryService::class);
        $industry->shouldReceive('refreshForUser')->once()
            ->andThrow(new \App\Services\Esi\EsiRequestException('rate_limited', 300));
        $queued = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queued->shouldReceive('release')->once()->with(Mockery::on(fn ($delay) => $delay >= 301 && $delay <= 305));
        $job = new RefreshIndustryData($user->id);
        $job->setJob($queued);
        $job->handle($industry);
        $this->assertGreaterThan(now()->addMinutes(29)->timestamp, $job->retryUntil()->getTimestamp());
    }
}
