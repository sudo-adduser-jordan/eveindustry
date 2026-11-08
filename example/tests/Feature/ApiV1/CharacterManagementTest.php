<?php

namespace Tests\Feature\ApiV1;

use App\Models\Character;
use App\Models\User;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Pilot', 'email' => uniqid().'@test.com', 'is_active' => true]);
    }

    private function character(User $user, int $id, bool $main = false): Character
    {
        return Character::create(['character_id' => $id, 'user_id' => $user->id, 'name' => 'Pilot '.$id, 'corporation_id' => 98000001, 'is_main' => $main]);
    }

    public function test_writes_require_login_and_deny_other_users_characters(): void
    {
        $owner = $this->user();
        $character = $this->character($owner, 101, true);
        $this->postJson('/api/v1/characters/101/main')->assertUnauthorized();
        $this->deleteJson('/api/v1/characters/101')->assertUnauthorized();
        $this->actingAs($this->user())->postJson('/api/v1/characters/101/main')->assertForbidden();
        $this->deleteJson('/api/v1/characters/101')->assertForbidden();
        $this->assertTrue($character->fresh()->is_main);
    }

    public function test_main_switch_and_removal_preserve_one_main_character(): void
    {
        $user = $this->user();
        $first = $this->character($user, 101, true);
        $second = $this->character($user, 102);
        $this->actingAs($user)->postJson('/api/v1/characters/102/main')->assertNoContent();
        $this->assertFalse($first->fresh()->is_main);
        $this->assertTrue($second->fresh()->is_main);
        $this->deleteJson('/api/v1/characters/102')->assertNoContent();
        $this->assertTrue($first->fresh()->is_main);
        $this->deleteJson('/api/v1/characters/101')->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
        $this->assertDatabaseHas('characters', ['character_id' => 101]);
    }

    public function test_api_account_deletion_revokes_tokens_and_ends_session(): void
    {
        $user = $this->user();
        $this->character($user, 101, true)->update(['refresh_token' => 'refresh-token']);
        $this->mock(EsiService::class)->shouldReceive('revokeToken')->once()->with('refresh-token');
        $this->actingAs($user)->withHeaders(['Origin' => 'http://localhost'])->deleteJson('/api/v1/account')->assertNoContent();
        $this->assertGuest('web');
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('characters', ['character_id' => 101]);
    }
}
