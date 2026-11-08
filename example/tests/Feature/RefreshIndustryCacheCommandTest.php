<?php

namespace Tests\Feature;

use App\Jobs\RefreshIndustryData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RefreshIndustryCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dispatches_jobs_for_active_users(): void
    {
        Queue::fake();

        User::create(['name' => 'Active 1', 'email' => 'a1@example.com', 'is_active' => true, 'last_login_at' => now()]);
        User::create(['name' => 'Active 2', 'email' => 'a2@example.com', 'is_active' => true, 'last_login_at' => now()]);
        User::create(['name' => 'Inactive', 'email' => 'i@example.com', 'is_active' => false]);

        foreach (User::all() as $user) {
            \App\Models\Character::create([
                'character_id' => 100000 + $user->id, 'user_id' => $user->id,
                'name' => 'Eligible Pilot', 'corporation_id' => 98, 'is_esi_active' => true,
                'scopes' => \App\Services\RefreshEligibility::INDUSTRY_SCOPES,
            ]);
        }

        $this->artisan('industry:refresh')
            ->assertExitCode(0);

        Queue::assertPushed(RefreshIndustryData::class, 2);
    }

    public function test_command_handles_no_users(): void
    {
        Queue::fake();

        foreach (User::all() as $user) {
            \App\Models\Character::create([
                'character_id' => 100000 + $user->id, 'user_id' => $user->id,
                'name' => 'Eligible Pilot', 'corporation_id' => 98, 'is_esi_active' => true,
                'scopes' => \App\Services\RefreshEligibility::INDUSTRY_SCOPES,
            ]);
        }

        $this->artisan('industry:refresh')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
