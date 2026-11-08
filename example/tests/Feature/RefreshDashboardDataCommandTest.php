<?php

namespace Tests\Feature;

use App\Jobs\RefreshDashboardStats;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RefreshDashboardDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dispatches_jobs_for_active_users(): void
    {
        Queue::fake();

        $activeUser1 = User::create(['name' => 'Active 1', 'email' => 'a1@example.com', 'is_active' => true, 'last_login_at' => now()->subDay()]);
        $activeUser2 = User::create(['name' => 'Active 2', 'email' => 'a2@example.com', 'is_active' => true, 'last_login_at' => now()->subHours(2)]);
        $inactiveUser = User::create(['name' => 'Inactive', 'email' => 'i@example.com', 'is_active' => false, 'last_login_at' => now()->subDay()]);

        foreach (User::all() as $user) {
            \App\Models\Character::create([
                'character_id' => 100000 + $user->id, 'user_id' => $user->id,
                'name' => 'Eligible Pilot', 'corporation_id' => 98, 'is_esi_active' => true,
                'scopes' => \App\Services\RefreshEligibility::INDUSTRY_SCOPES,
            ]);
        }

        $this->artisan('dashboard:refresh')
            ->assertExitCode(0);

        Queue::assertPushed(RefreshDashboardStats::class, 2);
    }

    public function test_command_skips_dormant_users(): void
    {
        Queue::fake();

        User::create(['name' => 'Recent', 'email' => 'recent@example.com', 'is_active' => true, 'last_login_at' => now()->subDay()]);
        User::create(['name' => 'Dormant', 'email' => 'dormant@example.com', 'is_active' => true, 'last_login_at' => now()->subDays(30)]);
        User::create(['name' => 'Never', 'email' => 'never@example.com', 'is_active' => true, 'last_login_at' => null]);

        foreach (User::all() as $user) {
            \App\Models\Character::create([
                'character_id' => 100000 + $user->id, 'user_id' => $user->id,
                'name' => 'Eligible Pilot', 'corporation_id' => 98, 'is_esi_active' => true,
                'scopes' => \App\Services\RefreshEligibility::INDUSTRY_SCOPES,
            ]);
        }

        $this->artisan('dashboard:refresh')
            ->assertExitCode(0);

        Queue::assertPushed(RefreshDashboardStats::class, 1);
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

        $this->artisan('dashboard:refresh')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
