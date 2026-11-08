<?php

namespace Tests\Feature\ApiV1\Corp;

use App\Models\EssTrackedSystem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Corp\CorpFixtures;
use Tests\TestCase;

class EssTest extends TestCase
{
    use CorpFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
    }

    private function track(int $corporationId, string $name, array $attrs = []): EssTrackedSystem
    {
        return EssTrackedSystem::create(array_merge([
            'corporation_id' => $corporationId,
            'solar_system_id' => random_int(30000000, 30999999),
            'solar_system_name' => $name,
            'cycle_minutes' => 60,
            'cycle_anchor_at' => '2026-10-21 11:30:00',
        ], $attrs));
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/corporation/ess')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_own_corporation_systems_paginated(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);

        $zeta = $this->track(98000001, 'Zeta', ['notes' => 'watch']);
        $this->track(98000001, 'Alpha', ['cycle_minutes' => 20, 'cycle_anchor_at' => '2026-10-21 13:00:00']);
        $this->track(98000001, 'Mu');
        $this->track(99999999, 'Beta');
        for ($i = 1; $i <= 6; $i++) {
            $zeta->payoutEvents()->create(['occurred_at' => "2026-10-0{$i} 10:00:00", 'amount_isk' => $i * 1000]);
        }

        $response = $this->actingAs($user)->getJson('/api/v1/corporation/ess?per_page=2');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'solar_system_id', 'solar_system_name', 'cycle_minutes', 'cycle_anchor_at', 'next_payout_at', 'seconds_until_next_payout', 'notes', 'recent_payouts']],
                'meta' => ['pagination' => ['page', 'per_page', 'total', 'last_page']],
            ])
            ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->assertJsonPath('data.*.solar_system_name', ['Alpha', 'Mu'])
            ->assertJsonPath('data.0.next_payout_at', '2026-10-21T13:00:00+00:00')
            ->assertJsonPath('data.0.seconds_until_next_payout', 3600);

        $page2 = $this->actingAs($user)->getJson('/api/v1/corporation/ess?per_page=2&page=2')->assertOk();
        $page2->assertJsonPath('data.*.solar_system_name', ['Zeta'])
            ->assertJsonPath('data.0.notes', 'watch')
            ->assertJsonCount(5, 'data.0.recent_payouts')
            ->assertJsonPath('data.0.recent_payouts.0.occurred_at', '2026-10-06T10:00:00+00:00')
            ->assertJsonPath('data.0.recent_payouts.0.amount_isk', 6000);
    }

    public function test_falls_back_to_a_non_main_character(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user, ['is_main' => false, 'corporation_id' => 98000555]);
        $this->track(98000555, 'Alpha');

        $this->actingAs($user)->getJson('/api/v1/corporation/ess')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_bad_pagination_is_422(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);

        $this->actingAs($user)->getJson('/api/v1/corporation/ess?page=abc')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['page']);
    }

    public function test_forbidden_without_characters(): void
    {
        $this->actingAs($this->makeUser())->getJson('/api/v1/corporation/ess')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_forbidden_when_main_has_no_corporation(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user, ['corporation_id' => 0]);

        $this->actingAs($user)->getJson('/api/v1/corporation/ess')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }
}
