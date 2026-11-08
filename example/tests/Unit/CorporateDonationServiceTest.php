<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\CorporateDonationConfig;
use App\Models\User;
use App\Services\CorporateDonationService;
use App\Services\EsiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CorporateDonationServiceTest extends TestCase
{
    use RefreshDatabase;

    private CorporateDonationService $service;

    private $esiMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->esiMock = Mockery::mock(EsiService::class);
        $this->service = new CorporateDonationService($this->esiMock);
    }

    public function test_filter_donations_only_includes_player_donations(): void
    {
        $journal = [
            ['ref_type' => 'player_donation', 'amount' => 1000, 'date' => '2026-02-15T12:00:00Z', 'first_party_id' => 100, 'id' => 1, 'reason' => ''],
            ['ref_type' => 'market_transaction', 'amount' => 500, 'date' => '2026-02-15T12:00:00Z', 'first_party_id' => 101, 'id' => 2, 'reason' => ''],
            ['ref_type' => 'player_donation', 'amount' => 2000, 'date' => '2026-02-16T12:00:00Z', 'first_party_id' => 102, 'id' => 3, 'reason' => 'Thanks'],
        ];

        $from = Carbon::parse('2026-02-01');
        $to = Carbon::parse('2026-02-28');

        $result = $this->service->filterDonations($journal, $from, $to);

        $this->assertCount(2, $result);
        $this->assertEquals(1000, $result[0]['amount']);
        $this->assertEquals(2000, $result[1]['amount']);
    }

    public function test_filter_donations_respects_date_range(): void
    {
        $journal = [
            ['ref_type' => 'player_donation', 'amount' => 1000, 'date' => '2026-02-10T12:00:00Z', 'first_party_id' => 100, 'id' => 1, 'reason' => ''],
            ['ref_type' => 'player_donation', 'amount' => 2000, 'date' => '2026-02-15T12:00:00Z', 'first_party_id' => 101, 'id' => 2, 'reason' => ''],
            ['ref_type' => 'player_donation', 'amount' => 3000, 'date' => '2026-02-20T12:00:00Z', 'first_party_id' => 102, 'id' => 3, 'reason' => ''],
        ];

        $from = Carbon::parse('2026-02-12')->startOfDay();
        $to = Carbon::parse('2026-02-18')->endOfDay();

        $result = $this->service->filterDonations($journal, $from, $to);

        $this->assertCount(1, $result);
        $this->assertEquals(2000, $result[0]['amount']);
    }

    public function test_filter_donations_excludes_negative_amounts(): void
    {
        $journal = [
            ['ref_type' => 'player_donation', 'amount' => 1000, 'date' => '2026-02-15T12:00:00Z', 'first_party_id' => 100, 'id' => 1, 'reason' => ''],
            ['ref_type' => 'player_donation', 'amount' => -500, 'date' => '2026-02-15T12:00:00Z', 'first_party_id' => 101, 'id' => 2, 'reason' => ''],
        ];

        $from = Carbon::parse('2026-02-01');
        $to = Carbon::parse('2026-02-28');

        $result = $this->service->filterDonations($journal, $from, $to);

        $this->assertCount(1, $result);
        $this->assertEquals(1000, $result[0]['amount']);
    }

    public function test_aggregate_by_donator_groups_and_sums(): void
    {
        $donations = [
            ['first_party_id' => 100, 'amount' => 1000, 'date' => '2026-02-15 12:00:00', 'id' => 1, 'reason' => ''],
            ['first_party_id' => 100, 'amount' => 2000, 'date' => '2026-02-16 12:00:00', 'id' => 2, 'reason' => ''],
            ['first_party_id' => 200, 'amount' => 500, 'date' => '2026-02-15 12:00:00', 'id' => 3, 'reason' => ''],
        ];

        $this->esiMock->shouldReceive('getCharacter')
            ->with(100)
            ->andReturn(['name' => 'Donator Alpha']);

        $this->esiMock->shouldReceive('getCharacter')
            ->with(200)
            ->andReturn(['name' => 'Donator Beta']);

        $result = $this->service->aggregateByDonator($donations);

        $this->assertCount(2, $result);

        // Sorted by total_amount descending
        $this->assertEquals('Donator Alpha', $result[0]['donator_name']);
        $this->assertEquals(3000, $result[0]['total_amount']);
        $this->assertEquals(2, $result[0]['donation_count']);

        $this->assertEquals('Donator Beta', $result[1]['donator_name']);
        $this->assertEquals(500, $result[1]['total_amount']);
        $this->assertEquals(1, $result[1]['donation_count']);
    }

    public function test_aggregate_by_donator_skips_null_party_id(): void
    {
        $donations = [
            ['first_party_id' => null, 'amount' => 1000, 'date' => '2026-02-15 12:00:00', 'id' => 1, 'reason' => ''],
            ['first_party_id' => 100, 'amount' => 500, 'date' => '2026-02-15 12:00:00', 'id' => 2, 'reason' => ''],
        ];

        $this->esiMock->shouldReceive('getCharacter')
            ->with(100)
            ->andReturn(['name' => 'Valid Donator']);

        $result = $this->service->aggregateByDonator($donations);

        $this->assertCount(1, $result);
        $this->assertEquals('Valid Donator', $result[0]['donator_name']);
    }

    public function test_get_user_wallet_character_returns_character_with_scope(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        Character::create([
            'character_id' => 12345,
            'user_id' => $user->id,
            'name' => 'Wallet Char',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-wallet.read_corporation_wallets.v1'],
        ]);

        $result = $this->service->getUserWalletCharacter($user->id);

        $this->assertNotNull($result);
        $this->assertEquals(12345, $result->character_id);
    }

    public function test_get_user_wallet_characters_returns_all_characters_with_scope(): void
    {
        $user = User::create([
            'name' => 'Multi Char User',
            'email' => 'multi@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        Character::create([
            'character_id' => 11111,
            'user_id' => $user->id,
            'name' => 'Wallet Char One',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'access_token' => 'token1',
            'refresh_token' => 'refresh1',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-wallet.read_corporation_wallets.v1'],
        ]);

        Character::create([
            'character_id' => 22222,
            'user_id' => $user->id,
            'name' => 'Wallet Char Two',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'access_token' => 'token2',
            'refresh_token' => 'refresh2',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-wallet.read_corporation_wallets.v1', 'esi-industry.read_character_jobs.v1'],
        ]);

        Character::create([
            'character_id' => 33333,
            'user_id' => $user->id,
            'name' => 'No Wallet Char',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'access_token' => 'token3',
            'refresh_token' => 'refresh3',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-industry.read_character_jobs.v1'],
        ]);

        $result = $this->service->getUserWalletCharacters($user->id);

        $this->assertCount(2, $result);
        $characterIds = $result->pluck('character_id')->sort()->values()->all();
        $this->assertEquals([11111, 22222], $characterIds);
    }

    public function test_get_user_wallet_characters_returns_empty_when_no_scope(): void
    {
        $user = User::create([
            'name' => 'No Wallet User',
            'email' => 'nowallet@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        Character::create([
            'character_id' => 44444,
            'user_id' => $user->id,
            'name' => 'No Wallet Char',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-industry.read_character_jobs.v1'],
        ]);

        $result = $this->service->getUserWalletCharacters($user->id);

        $this->assertCount(0, $result);
    }

    public function test_get_user_wallet_character_returns_null_without_scope(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        Character::create([
            'character_id' => 12345,
            'user_id' => $user->id,
            'name' => 'No Wallet Char',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-industry.read_character_jobs.v1'],
        ]);

        $result = $this->service->getUserWalletCharacter($user->id);

        $this->assertNull($result);
    }

    public function test_enable_creates_config(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $character = Character::create([
            'character_id' => 12345,
            'user_id' => $user->id,
            'name' => 'Wallet Char',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-wallet.read_corporation_wallets.v1'],
        ]);

        $config = $this->service->enable($character, $user->id);

        $this->assertTrue($config->is_active);
        $this->assertEquals(98000, $config->corporation_id);
        $this->assertEquals('Test Corp', $config->corporation_name);
        $this->assertEquals(12345, $config->character_id);
        $this->assertEquals($user->id, $config->enabled_by_user_id);
    }

    public function test_disable_deactivates_config(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $character = Character::create([
            'character_id' => 12345,
            'user_id' => $user->id,
            'name' => 'Wallet Char',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-wallet.read_corporation_wallets.v1'],
        ]);

        $this->service->enable($character, $user->id);
        $this->service->disable(98000);

        $config = CorporateDonationConfig::where('corporation_id', 98000)->first();
        $this->assertFalse($config->is_active);
    }

    public function test_get_config_for_user_finds_active_config_for_same_corp(): void
    {
        $enabler = User::create([
            'name' => 'Enabler',
            'email' => 'enabler@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $enablerChar = Character::create([
            'character_id' => 12345,
            'user_id' => $enabler->id,
            'name' => 'Enabler Char',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-wallet.read_corporation_wallets.v1'],
        ]);

        // Viewer is in the same corp but doesn't have wallet scope
        Character::create([
            'character_id' => 67890,
            'user_id' => $viewer->id,
            'name' => 'Viewer Char',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'access_token' => 'token2',
            'refresh_token' => 'refresh2',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-industry.read_character_jobs.v1'],
        ]);

        $this->service->enable($enablerChar, $enabler->id);

        // Viewer should see the same config
        $config = $this->service->getConfigForUser($viewer->id);

        $this->assertNotNull($config);
        $this->assertEquals(98000, $config->corporation_id);
        $this->assertTrue($config->is_active);
    }

    public function test_get_donations_returns_empty_when_not_enabled(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        Character::create([
            'character_id' => 12345,
            'user_id' => $user->id,
            'name' => 'Test Char',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => [],
        ]);

        $result = $this->service->getDonationsForUser($user->id);

        $this->assertEmpty($result['donations']);
        $this->assertEquals(0, $result['total']);
        $this->assertNull($result['corporation_name']);
    }

    public function test_get_donations_for_user_filters_by_date_range(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $character = Character::create([
            'character_id' => 12345,
            'user_id' => $user->id,
            'name' => 'Wallet Char',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-wallet.read_corporation_wallets.v1'],
        ]);

        // Enable donation tracking
        $this->service->enable($character, $user->id);

        // Mock ESI: return wallet divisions
        $this->esiMock->shouldReceive('getCorporationWallets')
            ->andReturn([
                ['division' => 1, 'balance' => 1000000],
            ]);

        // Mock ESI: return journal with entries spanning multiple dates
        $this->esiMock->shouldReceive('getCorporationWalletJournal')
            ->andReturn([
                ['ref_type' => 'player_donation', 'amount' => 500, 'date' => '2026-01-20T10:00:00Z', 'first_party_id' => 100, 'id' => 1, 'reason' => ''],
                ['ref_type' => 'player_donation', 'amount' => 1000, 'date' => '2026-02-05T12:00:00Z', 'first_party_id' => 101, 'id' => 2, 'reason' => ''],
                ['ref_type' => 'player_donation', 'amount' => 2000, 'date' => '2026-02-10T14:00:00Z', 'first_party_id' => 102, 'id' => 3, 'reason' => ''],
                ['ref_type' => 'player_donation', 'amount' => 3000, 'date' => '2026-02-18T16:00:00Z', 'first_party_id' => 103, 'id' => 4, 'reason' => ''],
            ]);

        // Mock ESI: resolve character names
        $this->esiMock->shouldReceive('getCharacter')
            ->with(101)
            ->andReturn(['name' => 'Donor A']);
        $this->esiMock->shouldReceive('getCharacter')
            ->with(102)
            ->andReturn(['name' => 'Donor B']);

        // Request donations for Feb 1 - Feb 15 (should include entries 2 and 3 only)
        $result = $this->service->getDonationsForUser($user->id, '2026-02-01', '2026-02-15');

        $this->assertEquals('2026-02-01', $result['date_from']);
        $this->assertEquals('2026-02-15', $result['date_to']);
        $this->assertEquals(98000, $result['corporation_id']);
        $this->assertEquals('Test Corp', $result['corporation_name']);
        $this->assertCount(2, $result['donations']);
        $this->assertEquals(3000, $result['total']); // 1000 + 2000
    }

    public function test_get_donations_for_user_different_date_range_gives_different_results(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'datetest@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $character = Character::create([
            'character_id' => 22222,
            'user_id' => $user->id,
            'name' => 'Wallet Char',
            'corporation_id' => 99000,
            'corporation_name' => 'Date Test Corp',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-wallet.read_corporation_wallets.v1'],
        ]);

        $this->service->enable($character, $user->id);

        $this->esiMock->shouldReceive('getCorporationWallets')
            ->andReturn([['division' => 1, 'balance' => 500000]]);

        $journal = [
            ['ref_type' => 'player_donation', 'amount' => 100, 'date' => '2026-02-01T10:00:00Z', 'first_party_id' => 200, 'id' => 10, 'reason' => ''],
            ['ref_type' => 'player_donation', 'amount' => 200, 'date' => '2026-02-10T10:00:00Z', 'first_party_id' => 200, 'id' => 11, 'reason' => ''],
            ['ref_type' => 'player_donation', 'amount' => 300, 'date' => '2026-02-20T10:00:00Z', 'first_party_id' => 200, 'id' => 12, 'reason' => ''],
        ];

        $this->esiMock->shouldReceive('getCorporationWalletJournal')
            ->andReturn($journal);

        $this->esiMock->shouldReceive('getCharacter')
            ->with(200)
            ->andReturn(['name' => 'Same Donor']);

        // Narrow range: Feb 5-15 → only entry 11 (amount 200)
        $result1 = $this->service->getDonationsForUser($user->id, '2026-02-05', '2026-02-15');
        $this->assertEquals(200, $result1['total']);
        $this->assertCount(1, $result1['donations']);

        // Wide range: Feb 1-28 → all 3 entries (total 600)
        $result2 = $this->service->getDonationsForUser($user->id, '2026-02-01', '2026-02-28');
        $this->assertEquals(600, $result2['total']);
        $this->assertCount(1, $result2['donations']); // 1 donator, 3 donations
        $this->assertEquals(3, $result2['donations'][0]['donation_count']);
    }

    public function test_get_donations_swapped_dates_are_corrected(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'swap@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $character = Character::create([
            'character_id' => 33333,
            'user_id' => $user->id,
            'name' => 'Wallet Char',
            'corporation_id' => 97000,
            'corporation_name' => 'Swap Test Corp',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-wallet.read_corporation_wallets.v1'],
        ]);

        $this->service->enable($character, $user->id);

        $this->esiMock->shouldReceive('getCorporationWallets')
            ->andReturn([['division' => 1, 'balance' => 100000]]);

        $this->esiMock->shouldReceive('getCorporationWalletJournal')
            ->andReturn([
                ['ref_type' => 'player_donation', 'amount' => 1000, 'date' => '2026-02-10T12:00:00Z', 'first_party_id' => 300, 'id' => 20, 'reason' => ''],
            ]);

        $this->esiMock->shouldReceive('getCharacter')
            ->with(300)
            ->andReturn(['name' => 'Donor']);

        // Swapped dates: date_from > date_to — should still return results
        $result = $this->service->getDonationsForUser($user->id, '2026-02-28', '2026-02-01');
        $this->assertEquals('2026-02-01', $result['date_from']);
        $this->assertEquals('2026-02-28', $result['date_to']);
        $this->assertEquals(1000, $result['total']);
    }

    public function test_filter_donations_with_invalid_date_entry(): void
    {
        $journal = [
            ['ref_type' => 'player_donation', 'amount' => 1000, 'date' => '2026-02-15T12:00:00Z', 'first_party_id' => 100, 'id' => 1, 'reason' => ''],
            ['ref_type' => 'player_donation', 'amount' => 500, 'first_party_id' => 101, 'id' => 2, 'reason' => ''],
        ];

        $from = Carbon::parse('2026-02-01');
        $to = Carbon::parse('2026-02-28');

        $result = $this->service->filterDonations($journal, $from, $to);

        $this->assertCount(1, $result);
        $this->assertEquals(1000, $result[0]['amount']);
    }
}
