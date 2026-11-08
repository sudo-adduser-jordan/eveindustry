<?php

namespace Tests\Feature\ApiV1\Programs;

use App\Models\StationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Programs\ProgramsFixtures;
use Tests\TestCase;

class StructureTest extends TestCase
{
    use ProgramsFixtures, RefreshDatabase;

    private const RESOURCE_KEYS = ['id', 'activity_type', 'name', 'structure_type', 'rig_me', 'rig_te', 'system_security', 'solar_system_id', 'tax_rate', 'is_default', 'created_at', 'updated_at'];

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'activity_type' => 'manufacturing',
            'name' => 'Home Raitaru',
            'structure_type' => 'raitaru',
            'rig_me' => 't1',
            'rig_te' => 'none',
            'system_security' => 'lowsec',
            'solar_system_id' => 30000142,
            'tax_rate' => 2.5,
            'is_default' => false,
        ], $overrides);
    }

    private function config(int $userId, array $attrs = []): StationConfig
    {
        return StationConfig::create(array_merge($this->payload(), ['user_id' => $userId], $attrs));
    }

    public function test_requires_auth(): void
    {
        $this->postJson('/api/v1/structures', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->putJson('/api/v1/structures/1', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->deleteJson('/api/v1/structures/1')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->postJson('/api/v1/structures/1/default')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_store_returns_201_with_the_resource(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson('/api/v1/structures', $this->payload())
            ->assertCreated()
            ->assertJsonStructure(['data' => self::RESOURCE_KEYS, 'meta'])
            ->assertJsonPath('data.name', 'Home Raitaru')
            ->assertJsonPath('data.tax_rate', 2.5)
            ->assertJsonPath('data.is_default', false)
            ->assertJsonMissingPath('data.user_id');

        $this->assertSame($user->id, StationConfig::firstOrFail()->user_id);
        $this->assertSame(StationConfig::firstOrFail()->id, $response->json('data.id'));
    }

    public function test_store_as_default_unsets_other_defaults_of_that_activity_only(): void
    {
        $user = $this->makeUser();
        $old = $this->config($user->id, ['name' => 'Old', 'is_default' => true]);
        $react = $this->config($user->id, ['activity_type' => 'reaction', 'is_default' => true]);
        $theirs = $this->config($this->makeUser()->id, ['is_default' => true]);

        $this->actingAs($user)->postJson('/api/v1/structures', $this->payload(['name' => 'New', 'is_default' => true]))
            ->assertCreated()->assertJsonPath('data.is_default', true);

        $this->assertFalse($old->fresh()->is_default);
        $this->assertTrue($react->fresh()->is_default);
        $this->assertTrue($theirs->fresh()->is_default);
    }

    public function test_store_validation_is_422(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/v1/structures', [])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['activity_type', 'name', 'structure_type', 'rig_me', 'rig_te', 'system_security']);

        $this->actingAs($user)->postJson('/api/v1/structures', $this->payload(['tax_rate' => 51, 'structure_type' => 'keepstar']))
            ->assertStatus(422)->assertJsonValidationErrors(['tax_rate', 'structure_type']);

        $this->assertSame(0, StationConfig::count());
    }

    public function test_update_changes_only_sent_fields(): void
    {
        $user = $this->makeUser();
        $config = $this->config($user->id);

        $this->actingAs($user)->putJson("/api/v1/structures/{$config->id}", ['name' => 'Renamed', 'rig_me' => 't2'])
            ->assertOk()
            ->assertJsonStructure(['data' => self::RESOURCE_KEYS])
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.rig_me', 't2')
            ->assertJsonPath('data.structure_type', 'raitaru');

        $this->actingAs($user)->putJson("/api/v1/structures/{$config->id}", ['name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_update_to_default_unsets_the_others_of_that_activity(): void
    {
        $user = $this->makeUser();
        $a = $this->config($user->id, ['name' => 'A', 'is_default' => true]);
        $b = $this->config($user->id, ['name' => 'B']);
        $react = $this->config($user->id, ['activity_type' => 'reaction', 'is_default' => true]);

        $this->actingAs($user)->putJson("/api/v1/structures/{$b->id}", ['is_default' => true])
            ->assertOk()->assertJsonPath('data.is_default', true);

        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($react->fresh()->is_default);
    }

    public function test_destroy_returns_204(): void
    {
        $user = $this->makeUser();
        $config = $this->config($user->id);

        $this->actingAs($user)->deleteJson("/api/v1/structures/{$config->id}")->assertNoContent();

        $this->assertNull($config->fresh());
    }

    public function test_set_default_swaps_the_default_and_returns_the_structure(): void
    {
        $user = $this->makeUser();
        $a = $this->config($user->id, ['name' => 'A', 'is_default' => true]);
        $b = $this->config($user->id, ['name' => 'B']);
        $react = $this->config($user->id, ['activity_type' => 'reaction', 'is_default' => true]);

        $this->actingAs($user)->postJson("/api/v1/structures/{$b->id}/default")
            ->assertOk()
            ->assertJsonStructure(['data' => self::RESOURCE_KEYS])
            ->assertJsonPath('data.id', $b->id)
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_default);
        $this->assertTrue($react->fresh()->is_default);
    }

    public function test_other_users_structure_is_404_not_found(): void
    {
        $user = $this->makeUser();
        $theirs = $this->config($this->makeUser()->id);

        $this->actingAs($user)->putJson("/api/v1/structures/{$theirs->id}", ['name' => 'Hijack'])
            ->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->actingAs($user)->deleteJson("/api/v1/structures/{$theirs->id}")
            ->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->actingAs($user)->postJson("/api/v1/structures/{$theirs->id}/default")
            ->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->actingAs($user)->deleteJson('/api/v1/structures/99999')
            ->assertNotFound()->assertJsonPath('code', 'not_found');

        $this->assertSame('Home Raitaru', $theirs->fresh()->name);
        $this->assertFalse($theirs->fresh()->is_default);
    }
}
