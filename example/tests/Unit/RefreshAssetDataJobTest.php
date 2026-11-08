<?php

namespace Tests\Unit;

use App\Jobs\RefreshAssetData;
use App\Models\Character;
use App\Models\User;
use App\Services\AssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class RefreshAssetDataJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['assets.durable_snapshots' => false]);
    }

    private function createUser(): User
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'is_active' => true,
            'last_login_at' => now(),
        ]);
        Character::create([
            'character_id' => 90000001, 'user_id' => $user->id, 'name' => 'Pilot',
            'corporation_id' => 98000001, 'is_esi_active' => true,
            'scopes' => ['esi-assets.read_assets.v1'],
        ]);

        return $user;
    }

    public function test_job_clears_and_warms_asset_cache(): void
    {
        $user = $this->createUser();

        $assetMock = Mockery::mock(AssetService::class);
        $assetMock->shouldReceive('fetchAndCacheAssets')
            ->with($user->id)
            ->once()
            ->andReturn([]);
        $this->app->instance(AssetService::class, $assetMock);

        $job = new RefreshAssetData($user->id);
        $job->handle($assetMock);
    }

    public function test_job_skips_refresh_when_cache_is_fresh(): void
    {
        $user = $this->createUser();
        Cache::put("assets.user.{$user->id}", [['type_name' => 'Rifter']], 3600);
        Cache::put("assets.user.{$user->id}.cached_at", time(), 3600);

        $assetMock = Mockery::mock(AssetService::class);
        $assetMock->shouldNotReceive('fetchAndCacheAssets');

        (new RefreshAssetData($user->id))->handle($assetMock);
    }

    public function test_forced_job_refreshes_even_when_cache_is_fresh(): void
    {
        $user = $this->createUser();
        Cache::put("assets.user.{$user->id}", [['type_name' => 'Rifter']], 3600);
        Cache::put("assets.user.{$user->id}.cached_at", time(), 3600);

        $assetMock = Mockery::mock(AssetService::class);
        $assetMock->shouldReceive('fetchAndCacheAssets')
            ->with($user->id)
            ->once()
            ->andReturn([]);

        (new RefreshAssetData($user->id, force: true))->handle($assetMock);
    }

    public function test_unexpected_failure_is_retried_instead_of_reported_successful(): void
    {
        $user = $this->createUser();

        $assetMock = Mockery::mock(AssetService::class);
        $assetMock->shouldReceive('fetchAndCacheAssets')
            ->andThrow(new \RuntimeException('ESI error'));
        $this->app->instance(AssetService::class, $assetMock);

        $job = new RefreshAssetData($user->id);
        $this->expectException(\RuntimeException::class);
        $job->handle($assetMock);
    }

    public function test_job_has_correct_timeout_and_retry_configuration(): void
    {
        $job = new RefreshAssetData(1);

        $this->assertSame(180, $job->timeout, 'timeout should be 180s (3 minutes)');
        $this->assertSame(3, $job->tries, 'job should retry up to 3 times total');
        $this->assertSame([600], $job->backoff, 'retry delay should be 10 minutes (600 seconds)');
    }

    public function test_fresh_legacy_view_does_not_skip_initial_durable_backfill(): void
    {
        $user = $this->createUser();
        config(['assets.durable_snapshots' => true]);
        Cache::forever("assets.user.{$user->id}", [['item_id' => 42]]);
        Cache::forever("assets.user.{$user->id}.cached_at", time());
        $assets = Mockery::mock(AssetService::class);
        $assets->shouldReceive('snapshotMetadata')->with($user->id)->once()->andReturn(['pending' => true]);
        $assets->shouldReceive('fetchAndCacheAssets')->with($user->id)->once()->andReturn([]);

        (new RefreshAssetData($user->id))->handle($assets);
    }

    public function test_on_timeout_does_not_throw(): void
    {
        $job = new RefreshAssetData(42);

        // onTimeout() should be callable without exceptions; it only logs a warning
        $job->onTimeout();
        $this->assertTrue(true);
    }
}
