<?php

namespace Tests\Feature\ApiV1\Corp;

use App\Models\EssTrackedSystem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Corp\CorpFixtures;
use Tests\TestCase;

class EssWriteTest extends TestCase
{
    use CorpFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
    }

    private function track(int $corporationId, array $attrs = []): EssTrackedSystem
    {
        return EssTrackedSystem::create(array_merge([
            'corporation_id' => $corporationId,
            'solar_system_id' => 30000142,
            'solar_system_name' => 'Jita',
            'cycle_minutes' => 60,
            'cycle_anchor_at' => '2026-10-21 11:30:00',
        ], $attrs));
    }

    private function pilot()
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);

        return $user;
    }

    public function test_requires_auth(): void
    {
        $this->postJson('/api/v1/corporation/ess', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->putJson('/api/v1/corporation/ess/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/corporation/ess/1')->assertStatus(401);
        $this->postJson('/api/v1/corporation/ess/1/payouts', [])->assertStatus(401);
    }

    public function test_without_a_corporation_everything_is_403(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user, ['corporation_id' => 0]);

        $this->actingAs($user)->postJson('/api/v1/corporation/ess', ['solar_system_id' => 30000142, 'cycle_minutes' => 20])
            ->assertForbidden()
            ->assertJsonPath('message', 'No corporation associated with your account.');
    }

    public function test_store_resolves_hours_and_next_payout_into_an_anchor(): void
    {
        $this->actingAs($this->pilot())->postJson('/api/v1/corporation/ess', [
            'solar_system_id' => 30000142,
            'solar_system_name' => 'Jita',
            'cycle_hours' => 2,
            'cycle_minutes' => 45,
            'next_payout_in_hours' => 1,
            'next_payout_in_minutes' => 10,
            'notes' => 'watch',
        ])
            ->assertCreated()
            ->assertJsonPath('data.solar_system_name', 'Jita')
            ->assertJsonPath('data.cycle_minutes', 165)
            ->assertJsonPath('data.next_payout_at', '2026-10-21T13:10:00+00:00')
            ->assertJsonPath('data.seconds_until_next_payout', 4200)
            ->assertJsonPath('data.notes', 'watch')
            ->assertJsonPath('data.recent_payouts', []);

        $this->assertDatabaseHas('ess_tracked_systems', ['corporation_id' => 98000001, 'solar_system_id' => 30000142, 'cycle_minutes' => 165]);
    }

    public function test_store_updates_a_system_that_is_tracked_already(): void
    {
        $this->track(98000001);

        $this->actingAs($this->pilot())->postJson('/api/v1/corporation/ess', [
            'solar_system_id' => 30000142,
            'cycle_minutes' => 20,
            'cycle_anchor_at' => '2026-10-21T12:00:00Z',
        ])->assertCreated();

        $this->assertSame(1, EssTrackedSystem::count());
        $this->assertSame(20, EssTrackedSystem::first()->cycle_minutes);
    }

    public function test_store_validation(): void
    {
        $user = $this->pilot();

        $this->actingAs($user)->postJson('/api/v1/corporation/ess', [])
            ->assertStatus(422)->assertJsonValidationErrors(['solar_system_id', 'cycle_minutes']);

        $this->actingAs($user)->postJson('/api/v1/corporation/ess', ['solar_system_id' => 1, 'cycle_hours' => 24, 'cycle_minutes' => 30])
            ->assertStatus(422)->assertJsonValidationErrors(['cycle_minutes']);

        $this->actingAs($user)->postJson('/api/v1/corporation/ess', ['solar_system_id' => 1, 'cycle_minutes' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['cycle_minutes']);
    }

    public function test_update_keeps_the_anchor_unless_a_next_payout_is_given(): void
    {
        $system = $this->track(98000001);
        $user = $this->pilot();

        $this->actingAs($user)->putJson("/api/v1/corporation/ess/{$system->id}", ['cycle_minutes' => 30])
            ->assertOk()
            ->assertJsonPath('data.cycle_minutes', 30)
            ->assertJsonPath('data.cycle_anchor_at', '2026-10-21T11:30:00+00:00');

        $this->actingAs($user)->putJson("/api/v1/corporation/ess/{$system->id}", ['cycle_minutes' => 30, 'next_payout_in_minutes' => 5])
            ->assertOk()
            ->assertJsonPath('data.next_payout_at', '2026-10-21T12:05:00+00:00');
    }

    public function test_other_corporations_systems_are_403(): void
    {
        $other = $this->track(99999999);
        $user = $this->pilot();

        $this->actingAs($user)->putJson("/api/v1/corporation/ess/{$other->id}", ['cycle_minutes' => 30])->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/v1/corporation/ess/{$other->id}")->assertForbidden();
        $this->actingAs($user)->postJson("/api/v1/corporation/ess/{$other->id}/payouts", [])->assertForbidden();

        $this->assertDatabaseHas('ess_tracked_systems', ['id' => $other->id, 'cycle_minutes' => 60]);
    }

    public function test_destroy(): void
    {
        $system = $this->track(98000001);

        $this->actingAs($this->pilot())->deleteJson("/api/v1/corporation/ess/{$system->id}")->assertNoContent();

        $this->assertDatabaseMissing('ess_tracked_systems', ['id' => $system->id]);
    }

    public function test_log_payout_returns_the_system_with_the_new_event(): void
    {
        $system = $this->track(98000001);

        $this->actingAs($this->pilot())->postJson("/api/v1/corporation/ess/{$system->id}/payouts", [
            'occurred_at' => '2026-10-21T11:00:00Z',
            'amount_isk' => 1250000.5,
            'notes' => 'good one',
        ])
            ->assertCreated()
            ->assertJsonCount(1, 'data.recent_payouts')
            ->assertJsonPath('data.recent_payouts.0.occurred_at', '2026-10-21T11:00:00+00:00')
            ->assertJsonPath('data.recent_payouts.0.amount_isk', 1250000.5);

        $this->assertDatabaseHas('ess_payout_events', ['ess_tracked_system_id' => $system->id, 'notes' => 'good one']);
    }

    public function test_log_payout_defaults_to_now_and_validates(): void
    {
        $system = $this->track(98000001);
        $user = $this->pilot();

        $this->actingAs($user)->postJson("/api/v1/corporation/ess/{$system->id}/payouts", [])
            ->assertCreated()
            ->assertJsonPath('data.recent_payouts.0.occurred_at', '2026-10-21T12:00:00+00:00')
            ->assertJsonPath('data.recent_payouts.0.amount_isk', null);

        $this->actingAs($user)->postJson("/api/v1/corporation/ess/{$system->id}/payouts", ['amount_isk' => -1])
            ->assertStatus(422)->assertJsonValidationErrors(['amount_isk']);
    }
}
