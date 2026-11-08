<?php

namespace Tests\Feature\ApiV1\Corp;

use App\Models\CorporateDonationConfig;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Feature\LegacyApi\Corp\CorpFixtures;
use Tests\TestCase;

class DonationConfigTest extends TestCase
{
    use CorpFixtures, RefreshDatabase;

    private const SCOPE = 'esi-wallet.read_corporation_wallets.v1';

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/corporation/donations/status')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->postJson('/api/v1/corporation/donations/enable')->assertStatus(401);
        $this->postJson('/api/v1/corporation/donations/disable')->assertStatus(401);
        $this->postJson('/api/v1/corporation/donations/refresh')->assertStatus(401);
    }

    public function test_status_offers_wallet_characters_when_not_enabled(): void
    {
        $user = $this->makeUser();
        $char = $this->makeCharacter($user, ['scopes' => [self::SCOPE]]);

        $this->actingAs($user)->getJson('/api/v1/corporation/donations/status')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.can_enable', true)
            ->assertJsonPath('data.enabled_by_me', false)
            ->assertJsonPath('data.wallet_characters.0.character_id', $char->character_id)
            ->assertJsonPath('data.wallet_characters.0.corporation_name', 'Test Corp');
    }

    public function test_status_without_wallet_scope_cannot_enable(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);

        $this->actingAs($user)->getJson('/api/v1/corporation/donations/status')
            ->assertOk()
            ->assertJsonPath('data.can_enable', false)
            ->assertJsonPath('data.wallet_characters', []);
    }

    public function test_enable_then_status_names_provider_and_enabler(): void
    {
        $user = $this->makeUser();
        $char = $this->makeCharacter($user, ['scopes' => [self::SCOPE]]);

        $this->actingAs($user)->postJson('/api/v1/corporation/donations/enable', ['character_id' => $char->character_id])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.enabled_by_me', true)
            ->assertJsonPath('data.corporation_name', 'Test Corp')
            ->assertJsonPath('data.provider_character_name', 'Main Pilot');

        $this->assertDatabaseHas('corporate_donation_configs', ['corporation_id' => 98000001, 'is_active' => true, 'enabled_by_user_id' => $user->id]);
    }

    public function test_enable_defaults_to_first_eligible_character(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user, ['scopes' => [self::SCOPE]]);

        $this->actingAs($user)->postJson('/api/v1/corporation/donations/enable')
            ->assertOk()->assertJsonPath('data.enabled', true);
    }

    public function test_enable_without_eligible_character_is_422(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);

        $this->actingAs($user)->postJson('/api/v1/corporation/donations/enable')
            ->assertStatus(422)->assertJsonValidationErrors(['character_id']);
    }

    public function test_enable_with_ineligible_character_is_422(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user, ['scopes' => [self::SCOPE]]);

        $this->actingAs($user)->postJson('/api/v1/corporation/donations/enable', ['character_id' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['character_id']);
    }

    public function test_only_the_enabler_may_disable(): void
    {
        $enabler = $this->makeUser();
        $provider = $this->makeCharacter($enabler, ['scopes' => [self::SCOPE]]);
        $this->enableDonations($provider, $enabler->id);

        $colleague = $this->makeUser();
        $this->makeCharacter($colleague, ['character_id' => 90000099, 'is_main' => true]);

        $this->actingAs($colleague)->postJson('/api/v1/corporation/donations/disable')
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->assertTrue(CorporateDonationConfig::first()->is_active);

        $this->actingAs($enabler)->postJson('/api/v1/corporation/donations/disable')
            ->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertFalse(CorporateDonationConfig::first()->is_active);
    }

    public function test_refresh_clears_the_wallet_cache(): void
    {
        $this->mock(EsiService::class, function (MockInterface $esi) {
            $esi->shouldReceive('clearCorporationWalletJournalCache')->once()->with(98000001);
        });

        $user = $this->makeUser();
        $provider = $this->makeCharacter($user, ['scopes' => [self::SCOPE]]);
        $this->enableDonations($provider, $user->id);

        $this->actingAs($user)->postJson('/api/v1/corporation/donations/refresh')->assertNoContent();
    }

    public function test_refresh_is_forbidden_when_not_enabled(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);

        $this->actingAs($user)->postJson('/api/v1/corporation/donations/refresh')
            ->assertForbidden()
            ->assertJsonPath('message', 'Donation tracking is not enabled for your corporation.');
    }
}
