<?php

namespace Tests\Feature\ApiV1\Corp;

use App\Models\SdeSolarSystem;
use App\Models\StructureVulnerabilityWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Corp\CorpFixtures;
use Tests\TestCase;

class StructureWindowTest extends TestCase
{
    use CorpFixtures, RefreshDatabase;

    private function window(int $corporationId, array $attrs = []): StructureVulnerabilityWindow
    {
        return StructureVulnerabilityWindow::create(array_merge([
            'corporation_id' => $corporationId,
            'solar_system_id' => 30000142,
            'solar_system_name' => 'Jita',
            'structure_type' => 'upwell',
            'structure_name' => 'My Azbel',
            'day_of_week' => 3,
            'start_time_utc' => '18:00',
            'duration_minutes' => 60,
        ], $attrs));
    }

    private function pilot()
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);

        return $user;
    }

    private function payload(array $attrs = []): array
    {
        return array_merge([
            'solar_system_id' => 30000142,
            'solar_system_name' => 'Jita',
            'structure_type' => 'upwell',
            'structure_name' => 'My Azbel',
            'day_of_week' => 3,
            'start_time_utc' => '18:00',
            'duration_minutes' => 60,
        ], $attrs);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/corporation/structure-vulnerability')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->postJson('/api/v1/corporation/structure-vulnerability', [])->assertStatus(401);
        $this->putJson('/api/v1/corporation/structure-vulnerability/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/corporation/structure-vulnerability/1')->assertStatus(401);
    }

    public function test_without_a_corporation_everything_is_403(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user, ['corporation_id' => 0]);

        $this->actingAs($user)->getJson('/api/v1/corporation/structure-vulnerability')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonPath('message', 'No corporation associated with your account.');
    }

    public function test_lists_own_corporation_windows_by_system_and_type(): void
    {
        $this->window(98000001, ['solar_system_name' => 'Perimeter', 'structure_name' => 'B']);
        $this->window(98000001, ['solar_system_name' => 'Jita', 'structure_type' => 'skyhook', 'structure_name' => 'Hook', 'day_of_week' => null, 'start_time_utc' => '06:30:00']);
        $this->window(98000001, ['solar_system_name' => 'Jita', 'structure_name' => 'A']);
        $this->window(99999999, ['structure_name' => 'Foreign']);

        $this->actingAs($this->pilot())->getJson('/api/v1/corporation/structure-vulnerability')
            ->assertOk()
            ->assertJsonPath('data.*.structure_name', ['Hook', 'A', 'B'])
            ->assertJsonPath('data.0.structure_type', 'skyhook')
            ->assertJsonPath('data.0.day_of_week', null)
            ->assertJsonPath('data.0.start_time_utc', '06:30')
            ->assertJsonPath('meta.pagination.total', 3);
    }

    public function test_store_upwell_window(): void
    {
        $this->actingAs($this->pilot())->postJson('/api/v1/corporation/structure-vulnerability', $this->payload([
            'reinforce_exit_at' => '2026-11-01T12:00:00Z',
            'notes' => 'ours',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.structure_name', 'My Azbel')
            ->assertJsonPath('data.day_of_week', 3)
            ->assertJsonPath('data.start_time_utc', '18:00')
            ->assertJsonPath('data.reinforce_exit_at', '2026-11-01T12:00:00+00:00');

        $this->assertDatabaseHas('structure_vulnerability_windows', ['corporation_id' => 98000001, 'structure_type' => 'upwell', 'day_of_week' => 3]);
    }

    public function test_skyhook_ignores_day_of_week(): void
    {
        $this->actingAs($this->pilot())->postJson('/api/v1/corporation/structure-vulnerability', $this->payload([
            'structure_type' => 'skyhook',
            'start_time_utc' => '06:00',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.day_of_week', null);
    }

    public function test_system_name_defaults_to_the_sde_name(): void
    {
        SdeSolarSystem::create(['solar_system_id' => 30000142, 'solar_system_name' => 'Jita', 'security' => 0.9]);

        $this->actingAs($this->pilot())->postJson('/api/v1/corporation/structure-vulnerability', $this->payload(['solar_system_name' => null]))
            ->assertCreated()->assertJsonPath('data.solar_system_name', 'Jita');

        $this->actingAs($this->pilot())->postJson('/api/v1/corporation/structure-vulnerability', $this->payload(['solar_system_id' => 1, 'solar_system_name' => null]))
            ->assertCreated()->assertJsonPath('data.solar_system_name', 'System #1');
    }

    public function test_validation(): void
    {
        $user = $this->pilot();

        $this->actingAs($user)->postJson('/api/v1/corporation/structure-vulnerability', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['solar_system_id', 'structure_type', 'structure_name', 'start_time_utc', 'duration_minutes']);

        $this->actingAs($user)->postJson('/api/v1/corporation/structure-vulnerability', $this->payload(['structure_type' => 'tower', 'start_time_utc' => '6pm', 'duration_minutes' => 2]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['structure_type', 'start_time_utc', 'duration_minutes']);
    }

    public function test_update_and_destroy(): void
    {
        $window = $this->window(98000001);
        $user = $this->pilot();

        $this->actingAs($user)->putJson("/api/v1/corporation/structure-vulnerability/{$window->id}", $this->payload(['structure_name' => 'Renamed', 'duration_minutes' => 90]))
            ->assertOk()
            ->assertJsonPath('data.structure_name', 'Renamed')
            ->assertJsonPath('data.duration_minutes', 90);

        $this->actingAs($user)->deleteJson("/api/v1/corporation/structure-vulnerability/{$window->id}")->assertNoContent();
        $this->assertDatabaseMissing('structure_vulnerability_windows', ['id' => $window->id]);
    }

    public function test_other_corporations_windows_are_403(): void
    {
        $other = $this->window(99999999, ['structure_name' => 'Foreign']);
        $user = $this->pilot();

        $this->actingAs($user)->putJson("/api/v1/corporation/structure-vulnerability/{$other->id}", $this->payload())->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/v1/corporation/structure-vulnerability/{$other->id}")->assertForbidden();

        $this->assertDatabaseHas('structure_vulnerability_windows', ['id' => $other->id, 'structure_name' => 'Foreign']);
    }
}
