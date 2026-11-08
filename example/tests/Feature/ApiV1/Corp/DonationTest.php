<?php

namespace Tests\Feature\ApiV1\Corp;

use App\Models\CorporateDonationConfig;
use App\Services\EsiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Feature\LegacyApi\Corp\CorpFixtures;
use Tests\TestCase;

class DonationTest extends TestCase
{
    use CorpFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
    }

    private function fakeEsi(): void
    {
        $this->mock(EsiService::class, function (MockInterface $esi) {
            $esi->shouldReceive('getCorporationWallets')->andReturn([
                ['division' => 1, 'balance' => 5000.5],
                ['division' => 2, 'balance' => 10],
            ]);
            $esi->shouldReceive('getCorporationWalletJournal')->with(\Mockery::any(), 98000001, 1)->andReturn([
                $this->journalEntry(1, 111, 1000, '2026-10-20T10:00:00Z', 'tax'),
                $this->journalEntry(2, 222, 5000, '2026-10-19T10:00:00Z'),
                $this->journalEntry(3, 333, 10, '2026-10-18T10:00:00Z'),
            ]);
            $esi->shouldReceive('getCorporationWalletJournal')->with(\Mockery::any(), 98000001, 2)->andReturn([
                $this->journalEntry(6, 222, 250, '2026-10-21T09:00:00Z'),
            ]);
            $esi->shouldReceive('getCharacter')->andReturnUsing(fn (int $id) => ['name' => "Pilot {$id}"]);
        });
    }

    private function enabledUser()
    {
        $user = $this->makeUser();
        $provider = $this->makeCharacter($user, ['scopes' => ['esi-wallet.read_corporation_wallets.v1']]);
        $this->enableDonations($provider, $user->id);

        return $user;
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/corporation/donations')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_donators_paginated_with_summary(): void
    {
        $this->fakeEsi();
        $user = $this->enabledUser();

        $response = $this->actingAs($user)->getJson('/api/v1/corporation/donations?per_page=2');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['donator_id', 'donator_name', 'portrait_url', 'total_amount', 'donation_count', 'donations' => [['id', 'date', 'amount', 'reason', 'wallet_division', 'wallet_name']]]],
                'meta' => ['summary' => ['corporation_id', 'corporation_name', 'date_from', 'date_to', 'total', 'wallet_divisions'], 'pagination'],
            ])
            ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->assertJsonPath('data.*.donator_id', [222, 111])
            ->assertJsonPath('data.0.donator_name', 'Pilot 222')
            ->assertJsonPath('data.0.total_amount', 5250)
            ->assertJsonPath('data.0.donation_count', 2)
            ->assertJsonPath('data.0.donations.1.wallet_name', 'Division 2')
            ->assertJsonPath('meta.summary.total', 6260)
            ->assertJsonPath('meta.summary.corporation_id', 98000001)
            ->assertJsonPath('meta.summary.date_from', '2026-09-21')
            ->assertJsonPath('meta.summary.date_to', '2026-10-21')
            ->assertJsonPath('meta.summary.wallet_divisions.0', ['division' => 1, 'name' => 'Master Wallet', 'balance' => 5000.5])
            // Wallet ISK, not market-derived: no freshness block.
            ->assertJsonMissingPath('meta.freshness');

        $this->actingAs($user)->getJson('/api/v1/corporation/donations?per_page=2&page=2')
            ->assertOk()->assertJsonPath('data.*.donator_id', [333]);
    }

    public function test_filters_by_range_and_division(): void
    {
        $this->fakeEsi();
        $user = $this->enabledUser();

        $this->actingAs($user)->getJson('/api/v1/corporation/donations?date_from=2026-10-20&date_to=2026-10-21&division=1')
            ->assertOk()
            ->assertJsonPath('data.*.donator_id', [111])
            ->assertJsonPath('meta.summary.total', 1000)
            ->assertJsonPath('meta.summary.date_from', '2026-10-20');
    }

    public function test_bad_input_is_422(): void
    {
        $user = $this->enabledUser();

        $this->actingAs($user)->getJson('/api/v1/corporation/donations?date_from=yesterday&division=9')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['date_from', 'division']);

        $this->actingAs($user)->getJson('/api/v1/corporation/donations?date_from=2026-10-20&date_to=2026-10-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_forbidden_when_tracking_is_not_enabled(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user, ['scopes' => ['esi-wallet.read_corporation_wallets.v1']]);

        $this->actingAs($user)->getJson('/api/v1/corporation/donations')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_forbidden_when_tracking_was_disabled_or_belongs_to_another_corp(): void
    {
        $user = $this->makeUser();
        $character = $this->makeCharacter($user);
        $config = $this->enableDonations($character, $user->id);
        $config->update(['is_active' => false]);

        $other = $this->makeUser();
        $otherChar = $this->makeCharacter($other, ['corporation_id' => 98000999]);
        $this->enableDonations($otherChar, $other->id);

        $this->assertSame(2, CorporateDonationConfig::count());
        $this->actingAs($user)->getJson('/api/v1/corporation/donations')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_forbidden_without_characters(): void
    {
        $this->actingAs($this->makeUser())->getJson('/api/v1/corporation/donations')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_never_exposes_tokens(): void
    {
        $this->fakeEsi();
        $user = $this->enabledUser();

        $body = $this->actingAs($user)->getJson('/api/v1/corporation/donations')->assertOk()->getContent();

        $this->assertStringNotContainsString('secret-', $body);
        $this->assertStringNotContainsString('token', $body);
    }
}
