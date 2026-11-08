<?php

namespace Tests\Feature;

use App\Jobs\RefreshAssetData;
use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RefreshAssetCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    private static int $characterId = 90000000;

    /**
     * Create a user, optionally with one character.
     */
    private function makeUser(string $email, bool $isActive = true, ?int $lastLoginDaysAgo = 1, ?bool $esiActive = true): User
    {
        $user = User::create([
            'name' => $email,
            'email' => $email,
            'is_active' => $isActive,
            'last_login_at' => $lastLoginDaysAgo === null ? null : now()->subDays($lastLoginDaysAgo),
        ]);

        if ($esiActive !== null) {
            Character::create([
                'user_id' => $user->id,
                'character_id' => ++self::$characterId,
                'name' => "Char {$email}",
                'corporation_id' => 98000001,
                'is_esi_active' => $esiActive,
                'scopes' => ['esi-assets.read_assets.v1'],
            ]);
        }

        return $user;
    }

    public function test_command_dispatches_jobs_for_active_users(): void
    {
        Queue::fake();

        // Refreshes only users who are active, logged in within 7 days and
        // have an ESI-active character
        $active1 = $this->makeUser('a1@example.com');
        $active2 = $this->makeUser('a2@example.com');
        $this->makeUser('inactive@example.com', isActive: false);
        $this->makeUser('stale-login@example.com', lastLoginDaysAgo: 8);
        $this->makeUser('never-logged-in@example.com', lastLoginDaysAgo: null);
        $this->makeUser('esi-inactive@example.com', esiActive: false);
        $this->makeUser('no-character@example.com', esiActive: null);

        $this->artisan('assets:refresh')
            ->assertExitCode(0);

        Queue::assertPushed(RefreshAssetData::class, 2);
        Queue::assertPushed(RefreshAssetData::class, fn ($job) => $job->userId === $active1->id);
        Queue::assertPushed(RefreshAssetData::class, fn ($job) => $job->userId === $active2->id);
        // Scheduled refreshes keep the freshness check
        Queue::assertNotPushed(RefreshAssetData::class, fn ($job) => $job->force);
    }

    public function test_command_handles_no_users(): void
    {
        Queue::fake();

        $this->artisan('assets:refresh')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
