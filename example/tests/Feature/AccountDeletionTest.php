<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_delete_their_account(): void
    {
        $this->mock(EsiService::class, function ($mock) {
            $mock->shouldReceive('revokeToken')->never();
        });

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->delete(route('account.destroy'));

        $response->assertRedirect(route('home'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_deleting_account_removes_all_characters(): void
    {
        $this->mock(EsiService::class, function ($mock) {
            $mock->shouldReceive('revokeToken')->once()->with('refresh_token_abc');
        });

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'is_active' => true,
        ]);

        Character::create([
            'character_id' => 11111,
            'user_id' => $user->id,
            'name' => 'Char One',
            'corporation_id' => 98000001,
            'is_main' => true,
            'refresh_token' => 'refresh_token_abc',
        ]);

        $this->actingAs($user)->delete(route('account.destroy'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('characters', ['character_id' => 11111]);
    }

    public function test_deleting_account_revokes_all_character_tokens(): void
    {
        $mock = $this->mock(EsiService::class, function ($mock) {
            $mock->shouldReceive('revokeToken')->once()->with('token_one');
            $mock->shouldReceive('revokeToken')->once()->with('token_two');
        });

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'is_active' => true,
        ]);

        Character::create([
            'character_id' => 22222,
            'user_id' => $user->id,
            'name' => 'Char One',
            'corporation_id' => 98000001,
            'is_main' => true,
            'refresh_token' => 'token_one',
        ]);

        Character::create([
            'character_id' => 33333,
            'user_id' => $user->id,
            'name' => 'Char Two',
            'corporation_id' => 98000001,
            'is_main' => false,
            'refresh_token' => 'token_two',
        ]);

        $this->actingAs($user)->delete(route('account.destroy'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_deleting_account_logs_user_out(): void
    {
        $this->mock(EsiService::class, function ($mock) {
            $mock->shouldReceive('revokeToken')->never();
        });

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'is_active' => true,
        ]);

        $this->actingAs($user)->delete(route('account.destroy'));

        $this->assertGuest();
    }

    public function test_unauthenticated_user_cannot_delete_account(): void
    {
        $response = $this->delete(route('account.destroy'));

        $response->assertRedirect(route('login'));
    }
}
