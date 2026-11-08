<?php

namespace Tests\Feature\ApiV1\Planning;

use App\Models\WholemapSignature;
use App\Models\WholemapSystemNote;
use App\Models\WormholeConnection;
use App\Models\WormholeJumpLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Planning\PlanningFixtures;
use Tests\TestCase;

class WholemapTest extends TestCase
{
    use PlanningFixtures, RefreshDatabase;

    private function sigBody(array $over = []): array
    {
        return array_merge(['signature_id' => 'ABC-123', 'system_id' => 31000005, 'system_name' => 'J100000', 'type' => 'wormhole'], $over);
    }

    public function test_logged_out_is_401_with_code(): void
    {
        $this->postJson('/api/v1/wholemap/signatures', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->putJson('/api/v1/wholemap/notes', [])->assertStatus(401);
    }

    public function test_store_signature_201_envelope(): void
    {
        $user = $this->makeUser(['name' => 'Alice']);
        $this->actingAs($user);

        $this->postJson('/api/v1/wholemap/signatures', $this->sigBody())
            ->assertStatus(201)
            ->assertJsonPath('data.signature_id', 'ABC-123')
            ->assertJsonPath('data.created_by', 'Alice')
            ->assertJsonPath('data.locked', false)
            ->assertJsonPath('data.connection', null)
            ->assertJsonStructure(['data' => ['id', 'type', 'system_id'], 'meta']);
        $this->assertDatabaseHas('wholemap_signatures', ['user_id' => $user->id, 'signature_id' => 'ABC-123']);
    }

    public function test_store_signature_422(): void
    {
        $this->actingAs($this->makeUser());
        $this->postJson('/api/v1/wholemap/signatures', ['type' => 'nope'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['system_id', 'system_name', 'type']);
    }

    public function test_update_signature_and_locked_and_foreign(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $sig = $this->makeSignature($user);

        $this->putJson("/api/v1/wholemap/signatures/{$sig->id}", ['name' => 'Home', 'type' => 'combat'])
            ->assertOk()->assertJsonPath('data.name', 'Home')->assertJsonPath('data.type', 'combat');

        $sig->update(['locked' => true]);
        $this->putJson("/api/v1/wholemap/signatures/{$sig->id}", ['name' => 'x'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertDatabaseHas('wholemap_signatures', ['id' => $sig->id, 'name' => 'Home']);

        $foreign = $this->makeSignature($this->makeUser());
        $this->putJson("/api/v1/wholemap/signatures/{$foreign->id}", ['name' => 'x'])
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->putJson('/api/v1/wholemap/signatures/99999', ['name' => 'x'])
            ->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_lock_toggle(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $sig = $this->makeSignature($user);

        $this->postJson("/api/v1/wholemap/signatures/{$sig->id}/lock-toggle")->assertOk()->assertJsonPath('data.locked', true);
        $this->postJson("/api/v1/wholemap/signatures/{$sig->id}/lock-toggle")->assertOk()->assertJsonPath('data.locked', false);

        $foreign = $this->makeSignature($this->makeUser());
        $this->postJson("/api/v1/wholemap/signatures/{$foreign->id}/lock-toggle")->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->assertFalse($foreign->fresh()->locked);
    }

    public function test_delete_signature_204_even_if_locked_and_cascades(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $conn = $this->makeConnection($user, $this->makeSignature($user, ['locked' => true]));

        $foreign = $this->makeSignature($this->makeUser());
        $this->deleteJson("/api/v1/wholemap/signatures/{$foreign->id}")->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->assertDatabaseHas('wholemap_signatures', ['id' => $foreign->id]);

        $this->deleteJson("/api/v1/wholemap/signatures/{$conn->signature_id}")->assertStatus(204)->assertNoContent();
        $this->assertDatabaseMissing('wholemap_signatures', ['id' => $conn->signature_id]);
        $this->assertSame(0, WormholeConnection::count());
    }

    public function test_paste_returns_created_and_updated(): void
    {
        $user = $this->makeUser(['name' => 'Alice']);
        $this->actingAs($user);
        $this->makeSignature($user, ['signature_id' => 'AAA-111', 'type' => 'unknown']);
        $this->makeSignature($user, ['signature_id' => 'LLL-111', 'locked' => true, 'type' => 'unknown']);
        $paste = "AAA-111\tCosmic Signature\tCombat Site\tHideout\nLLL-111\tCosmic Signature\tGas Site\tx\nNEW-222\tCosmic Signature\tWormhole\t";

        $this->postJson('/api/v1/wholemap/signatures/paste', ['system_id' => 31000005, 'system_name' => 'J100000', 'paste' => $paste])
            ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 1);
        $this->assertDatabaseHas('wholemap_signatures', ['signature_id' => 'AAA-111', 'type' => 'combat']);
        $this->assertDatabaseHas('wholemap_signatures', ['signature_id' => 'LLL-111', 'type' => 'unknown']);
        $this->assertDatabaseHas('wholemap_signatures', ['signature_id' => 'NEW-222', 'created_by' => 'Alice']);

        $this->postJson('/api/v1/wholemap/signatures/paste', [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_connection_lifecycle_and_ownership(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $sig = $this->makeSignature($user);

        $this->postJson('/api/v1/wholemap/connections', ['signature_id' => $sig->id, 'from_system_id' => 31000005, 'from_system_name' => 'J100000', 'size' => 'large'])
            ->assertStatus(201)->assertJsonPath('data.size', 'large')->assertJsonPath('data.signature_id', $sig->id);
        $conn = WormholeConnection::first();

        $this->putJson("/api/v1/wholemap/connections/{$conn->id}", ['to_system_name' => 'Jita', 'life' => 'critical'])
            ->assertOk()->assertJsonPath('data.to_system_name', 'Jita')->assertJsonPath('data.life', 'critical');
        $this->putJson("/api/v1/wholemap/connections/{$conn->id}", ['size' => 'huge'])->assertStatus(422);

        $foreignSig = $this->makeSignature($this->makeUser());
        $this->postJson('/api/v1/wholemap/connections', ['signature_id' => $foreignSig->id, 'from_system_id' => 1, 'from_system_name' => 'X'])
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->postJson('/api/v1/wholemap/connections', ['signature_id' => 99999, 'from_system_id' => 1, 'from_system_name' => 'X'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $foreign = $this->makeConnection($foreignSig->user, $foreignSig);
        $this->putJson("/api/v1/wholemap/connections/{$foreign->id}", [])->assertStatus(403);
        $this->deleteJson("/api/v1/wholemap/connections/{$foreign->id}")->assertStatus(403)->assertJsonPath('code', 'forbidden');

        $this->deleteJson("/api/v1/wholemap/connections/{$conn->id}")->assertStatus(204);
        $this->assertDatabaseMissing('wormhole_connections', ['id' => $conn->id]);
    }

    public function test_note_upsert_201_then_200_and_delete(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $body = ['system_id' => 30000142, 'system_name' => 'Jita', 'status' => 'hostile', 'comment' => 'camp'];

        $this->putJson('/api/v1/wholemap/notes', $body)->assertStatus(201)->assertJsonPath('data.status', 'hostile');
        $this->putJson('/api/v1/wholemap/notes', ['status' => 'friendly'] + $body)->assertStatus(200)->assertJsonPath('data.status', 'friendly');
        $this->assertSame(1, WholemapSystemNote::count());
        $this->putJson('/api/v1/wholemap/notes', ['status' => 'bad'] + $body)->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $note = WholemapSystemNote::first();
        $foreign = WholemapSystemNote::create(['user_id' => $this->makeUser()->id, 'system_id' => 1, 'system_name' => 'X', 'status' => 'unknown']);
        $this->deleteJson("/api/v1/wholemap/notes/{$foreign->id}")->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->deleteJson("/api/v1/wholemap/notes/{$note->id}")->assertStatus(204);
        $this->assertDatabaseMissing('wholemap_system_notes', ['id' => $note->id]);
    }

    public function test_jump_log(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $conn = $this->makeConnection($user);

        $this->postJson('/api/v1/wholemap/jump-logs', ['connection_id' => $conn->id, 'ship_type_name' => 'Rifter', 'ship_mass' => 1200000])
            ->assertStatus(201)->assertJsonPath('data.ship_type_name', 'Rifter')->assertJsonPath('data.connection_id', $conn->id);
        $this->assertSame(1, WormholeJumpLog::count());
        $this->assertNotNull(WormholeJumpLog::first()->jumped_at);

        $foreign = $this->makeConnection($this->makeUser());
        $this->postJson('/api/v1/wholemap/jump-logs', ['connection_id' => $foreign->id])->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->postJson('/api/v1/wholemap/jump-logs', ['connection_id' => 99999])->assertStatus(422);
        $this->assertSame(1, WormholeJumpLog::count());
        $this->assertSame(1, WholemapSignature::where('user_id', $user->id)->count());
    }
}
