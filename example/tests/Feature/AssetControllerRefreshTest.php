<?php

namespace Tests\Feature;

use App\Jobs\RefreshAssetData;
use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AssetControllerRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'is_active' => true,
        ]);
    }

    public function test_refresh_dispatches_job_instead_of_clearing_cache_directly(): void
    {
        Queue::fake();

        $user = $this->createUser();

        $response = $this->actingAs($user)->post(route('assets.refresh'));

        $response->assertRedirect(route('assets.index'));
        Queue::assertPushed(RefreshAssetData::class, function ($job) use ($user) {
            return $job->userId === $user->id;
        });
    }

    public function test_refresh_does_not_directly_clear_the_asset_cache(): void
    {
        Queue::fake();

        $user = $this->createUser();

        // Pre-populate the asset cache
        \Illuminate\Support\Facades\Cache::put("assets.user.{$user->id}", [['type_name' => 'Rifter']], 3600);
        \Illuminate\Support\Facades\Cache::put("assets.user.{$user->id}.cached_at", time(), 3600);

        $this->actingAs($user)->post(route('assets.refresh'));

        // Cache must still be intact — only the job is allowed to modify it
        $this->assertNotNull(\Illuminate\Support\Facades\Cache::get("assets.user.{$user->id}"));
        $this->assertNotNull(\Illuminate\Support\Facades\Cache::get("assets.user.{$user->id}.cached_at"));
    }

    public function test_refresh_forces_the_job_without_bypassing_source_freshness(): void
    {
        Queue::fake();

        $user = $this->createUser();
        $character = Character::create([
            'user_id' => $user->id,
            'character_id' => 90000001,
            'name' => 'Char',
            'corporation_id' => 98000001,
        ]);
        Cache::put("esi.assets.{$character->character_id}", [['item_id' => 1]], 3600);
        Cache::put('esi.corp_assets.98000001', [['item_id' => 2]], 3600);

        $this->actingAs($user)->post(route('assets.refresh'));

        // The job must not skip a user-requested refresh as "still fresh",
        // and must not reuse stale ESI results
        Queue::assertPushed(RefreshAssetData::class, fn ($job) => $job->userId === $user->id && $job->force === true);
        $this->assertNotNull(Cache::get("esi.assets.{$character->character_id}"));
        $this->assertNotNull(Cache::get('esi.corp_assets.98000001'));
    }

    public function test_refresh_shows_queued_message(): void
    {
        Queue::fake();

        $user = $this->createUser();

        $response = $this->actingAs($user)->post(route('assets.refresh'));

        $response->assertRedirect(route('assets.index'));
        $response->assertSessionHas('success', 'Asset refresh queued. Your data will be updated shortly.');
    }
}
