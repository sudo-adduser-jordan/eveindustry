<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CharacterCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlink_removes_cached_private_asset_projection(): void
    {
        $user = User::create(['name' => 'Pilot', 'email' => 'unlink@test.example']);
        $character = Character::create(['character_id' => 9001, 'user_id' => $user->id, 'name' => 'Alt', 'corporation_id' => 98000001]);
        Cache::forever("assets.user.{$user->id}", [['item_id' => 42]]);
        Cache::forever("assets.user.{$user->id}.cached_at", time());

        $character->delete();

        $this->assertNull(Cache::get("assets.user.{$user->id}"));
        $this->assertNull(Cache::get("assets.user.{$user->id}.cached_at"));
    }

    public function test_role_or_scope_change_invalidates_old_asset_projection(): void
    {
        $user = User::create(['name' => 'Pilot', 'email' => 'roles@test.example']);
        $character = Character::create(['character_id' => 9001, 'user_id' => $user->id, 'name' => 'Alt', 'corporation_id' => 98000001, 'roles' => ['Director']]);
        Cache::forever("assets.user.{$user->id}", [['item_id' => 42]]);

        $character->update(['roles' => [], 'is_director_ceo' => false]);

        $this->assertNull(Cache::get("assets.user.{$user->id}"));
    }
}
