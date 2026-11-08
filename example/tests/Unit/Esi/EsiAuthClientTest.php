<?php

namespace Tests\Unit\Esi;

use App\Models\Character;
use App\Models\User;
use App\Services\Esi\EsiAuthClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EsiAuthClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['esi.coordination_store' => 'array']);
        Cache::flush();
    }

    private function character(): Character
    {
        $user = User::create(['name' => 'Pilot', 'email' => 'auth@test.test', 'password' => 'test', 'is_active' => true]);

        return Character::create(['character_id' => 123, 'corporation_id' => 98000, 'corporation_name' => 'Corp', 'user_id' => $user->id, 'name' => 'Pilot', 'access_token' => 'access', 'refresh_token' => 'refresh', 'token_expires_at' => now()->subMinute(), 'is_esi_active' => true]);
    }

    public function test_sso_outage_preserves_active_status_and_credentials(): void
    {
        $character = $this->character();
        Http::fake(['*' => Http::response(['error' => 'server_error'], 503)]);
        $this->assertFalse(app(EsiAuthClient::class)->refreshCharacterToken($character));
        $this->assertTrue($character->fresh()->is_esi_active);
        $this->assertSame('refresh', $character->fresh()->refresh_token);
        $this->assertFalse(app(EsiAuthClient::class)->refreshCharacterToken($character));
        Http::assertSentCount(1);
    }

    public function test_invalid_client_configuration_does_not_deactivate_user_credentials(): void
    {
        $character = $this->character();
        Http::fake(['*' => Http::response(['error' => 'invalid_client'], 401)]);
        $this->assertFalse(app(EsiAuthClient::class)->refreshCharacterToken($character));
        $this->assertTrue($character->fresh()->is_esi_active);
    }

    public function test_only_explicit_invalid_grant_deactivates_character(): void
    {
        $character = $this->character();
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->assertFalse(app(EsiAuthClient::class)->refreshCharacterToken($character));
        $this->assertFalse($character->fresh()->is_esi_active);
    }

    public function test_stale_model_reloads_rotated_credential_and_avoids_second_refresh(): void
    {
        $character = $this->character();
        $stale = $character->fresh();
        Http::fake(['*' => Http::response(['access_token' => 'new', 'refresh_token' => 'rotated', 'expires_in' => 1199], 200)]);
        $client = app(EsiAuthClient::class);
        $this->assertTrue($client->refreshCharacterToken($character));
        $this->assertTrue($client->refreshCharacterToken($stale, true));
        $this->assertSame('rotated', $stale->refresh_token);
        Http::assertSentCount(1);
    }

    public function test_malformed_success_preserves_credentials(): void
    {
        $character = $this->character();
        Http::fake(['*' => Http::response(['expires_in' => 1199], 200)]);
        $this->assertFalse(app(EsiAuthClient::class)->refreshCharacterToken($character));
        $this->assertTrue($character->fresh()->is_esi_active);
        $this->assertSame('access', $character->fresh()->access_token);
    }
}
