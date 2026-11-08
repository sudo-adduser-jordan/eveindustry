<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RefreshExpiringTokensTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithCharacter(array $overrides = []): array
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'token-test@test.com',
            'is_active' => true,
        ]);

        $character = Character::create(array_merge([
            'character_id' => 123456,
            'user_id' => $user->id,
            'name' => 'Test Pilot',
            'corporation_id' => 98000001,
            'corporation_name' => 'Test Corp',
            'is_main' => true,
            'access_token' => 'test-token',
            'refresh_token' => 'test-refresh',
            'token_expires_at' => now()->addMinutes(5),
            'scopes' => [],
        ], $overrides));

        return [$user, $character];
    }

    public function test_command_refreshes_expiring_tokens(): void
    {
        [$user, $character] = $this->createUserWithCharacter([
            'token_expires_at' => now()->addMinutes(5),
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('refreshCharacterToken')
            ->once()
            ->with(Mockery::on(fn ($c) => $c->character_id === 123456))
            ->andReturn(true);
        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('esi:refresh-tokens')
            ->assertSuccessful();

        $character->refresh();
        $this->assertTrue($character->is_esi_active);
    }

    public function test_command_refreshes_already_expired_tokens(): void
    {
        [$user, $character] = $this->createUserWithCharacter([
            'token_expires_at' => now()->subMinutes(5),
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('refreshCharacterToken')
            ->once()
            ->with(Mockery::on(fn ($c) => $c->character_id === 123456))
            ->andReturn(true);
        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('esi:refresh-tokens')
            ->assertSuccessful();
    }

    public function test_command_refreshes_null_expiry_tokens(): void
    {
        [$user, $character] = $this->createUserWithCharacter([
            'token_expires_at' => null,
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('refreshCharacterToken')
            ->once()
            ->with(Mockery::on(fn ($c) => $c->character_id === 123456))
            ->andReturn(true);
        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('esi:refresh-tokens')
            ->assertSuccessful();
    }

    public function test_command_skips_tokens_not_expiring_soon(): void
    {
        [$user, $character] = $this->createUserWithCharacter([
            'token_expires_at' => now()->addMinutes(15),
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldNotReceive('refreshCharacterToken');
        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('esi:refresh-tokens')
            ->assertSuccessful();
    }

    public function test_command_skips_characters_without_refresh_token(): void
    {
        [$user, $character] = $this->createUserWithCharacter([
            'refresh_token' => null,
            'token_expires_at' => now()->subMinutes(5),
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldNotReceive('refreshCharacterToken');
        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('esi:refresh-tokens')
            ->assertSuccessful();
    }

    public function test_command_handles_refresh_failure_gracefully(): void
    {
        [$user, $character] = $this->createUserWithCharacter([
            'token_expires_at' => now()->addMinutes(5),
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('refreshCharacterToken')
            ->once()
            ->andReturn(false);
        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('esi:refresh-tokens')
            ->assertSuccessful();

        $character->refresh();
        $this->assertFalse($character->is_esi_active);
    }

    public function test_command_handles_exception_gracefully(): void
    {
        [$user, $character] = $this->createUserWithCharacter([
            'token_expires_at' => now()->addMinutes(5),
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('refreshCharacterToken')
            ->once()
            ->andThrow(new \RuntimeException('Connection failed'));
        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('esi:refresh-tokens')
            ->assertSuccessful();

        $character->refresh();
        $this->assertFalse($character->is_esi_active);
    }

    public function test_command_processes_multiple_characters(): void
    {
        $user = User::create([
            'name' => 'Multi User',
            'email' => 'multi-token@test.com',
            'is_active' => true,
        ]);

        Character::create([
            'character_id' => 111111,
            'user_id' => $user->id,
            'name' => 'Pilot Alpha',
            'corporation_id' => 98000001,
            'corporation_name' => 'Test Corp',
            'is_main' => true,
            'access_token' => 'token-1',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addMinutes(3),
            'scopes' => [],
        ]);

        Character::create([
            'character_id' => 222222,
            'user_id' => $user->id,
            'name' => 'Pilot Beta',
            'corporation_id' => 98000001,
            'corporation_name' => 'Test Corp',
            'is_main' => false,
            'access_token' => 'token-2',
            'refresh_token' => 'refresh-2',
            'token_expires_at' => now()->addMinutes(8),
            'scopes' => [],
        ]);

        // Third character with token far from expiring - should NOT be refreshed
        Character::create([
            'character_id' => 333333,
            'user_id' => $user->id,
            'name' => 'Pilot Gamma',
            'corporation_id' => 98000001,
            'corporation_name' => 'Test Corp',
            'is_main' => false,
            'access_token' => 'token-3',
            'refresh_token' => 'refresh-3',
            'token_expires_at' => now()->addMinutes(15),
            'scopes' => [],
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('refreshCharacterToken')
            ->twice()
            ->andReturn(true);
        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('esi:refresh-tokens')
            ->assertSuccessful();
    }
}
