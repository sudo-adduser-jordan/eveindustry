<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\User;
use App\Services\AssetService;
use App\Services\Esi\EsiRequestException;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class AssetRefreshIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['assets.durable_snapshots' => false]);
    }

    private function owner(int $count = 1): User
    {
        $user = User::create(['name' => 'Pilot', 'email' => 'assets@test.example', 'last_login_at' => now()]);
        for ($i = 1; $i <= $count; $i++) {
            Character::create([
                'character_id' => 9000 + $i, 'user_id' => $user->id, 'name' => 'Pilot '.$i,
                'corporation_id' => 98000001, 'is_esi_active' => true,
                'scopes' => ['esi-assets.read_assets.v1'],
            ]);
        }

        return $user;
    }

    public function test_complete_empty_inventory_replaces_old_data(): void
    {
        $user = $this->owner();
        Cache::forever("assets.user.{$user->id}", [['item_id' => 42]]);
        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterAssets')->once()->andReturn([]);

        $this->assertSame([], (new AssetService($esi))->fetchAndCacheAssets($user->id));
        $this->assertSame([], Cache::get("assets.user.{$user->id}"));
        $this->assertNotNull(Cache::get("assets.user.{$user->id}.cached_at"));
    }

    public function test_later_owner_failure_preserves_previous_complete_view_and_timestamp(): void
    {
        $user = $this->owner(2);
        $old = [['item_id' => 42]];
        Cache::forever("assets.user.{$user->id}", $old);
        Cache::forever("assets.user.{$user->id}.cached_at", 123);
        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterAssets')->withArgs(fn ($c) => $c->character_id === 9001)->once()->andReturn([]);
        $esi->shouldReceive('getCharacterAssets')->withArgs(fn ($c) => $c->character_id === 9002)->once()->andThrow(new EsiRequestException('incomplete', 30));

        try {
            (new AssetService($esi))->fetchAndCacheAssets($user->id);
            $this->fail('An incomplete owner must abort publication.');
        } catch (EsiRequestException $e) {
            $this->assertSame('incomplete', $e->outcome);
        }

        $this->assertSame($old, Cache::get("assets.user.{$user->id}"));
        $this->assertSame(123, Cache::get("assets.user.{$user->id}.cached_at"));
    }

    public function test_inactive_linked_character_is_never_fetched(): void
    {
        $user = $this->owner();
        Character::whereKey(9001)->update(['is_esi_active' => false]);
        $esi = Mockery::mock(EsiService::class);
        $esi->shouldNotReceive('getCharacterAssets');

        $this->assertSame([], (new AssetService($esi))->fetchAndCacheAssets($user->id));
    }
}
