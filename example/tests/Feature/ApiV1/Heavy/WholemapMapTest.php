<?php

namespace Tests\Feature\ApiV1\Heavy;

use App\Models\WholemapSignature;
use App\Models\WholemapSystemNote;
use App\Models\WormholeConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Heavy\HeavyFixtures;
use Tests\TestCase;

class WholemapMapTest extends TestCase
{
    use HeavyFixtures, RefreshDatabase;

    private function signature(int $userId, int $systemId, string $name, string $type = 'wormhole'): WholemapSignature
    {
        return WholemapSignature::create([
            'user_id' => $userId, 'signature_id' => 'ABC-123', 'system_id' => $systemId, 'system_name' => $name, 'type' => $type,
        ]);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/wholemap')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_returns_the_users_map_of_the_last_day(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $sig = $this->signature($user->id, 31000005, 'J123456');
        $this->signature($user->id, 30000142, 'Jita', 'data');
        $this->signature($other->id, 31000009, 'J999999');
        WormholeConnection::create([
            'user_id' => $user->id, 'signature_id' => $sig->id, 'from_system_id' => 31000005, 'from_system_name' => 'J123456',
            'to_system_id' => 30000142, 'to_system_name' => 'Jita', 'wormhole_type' => 'K162', 'size' => 'large', 'life' => 'critical', 'mass' => 'stable',
        ]);
        WholemapSystemNote::create(['user_id' => $user->id, 'system_id' => 31000005, 'system_name' => 'J123456', 'status' => 'hostile', 'comment' => 'Watch out']);

        $expired = $this->signature($user->id, 31000007, 'J777777');
        $expired->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->actingAs($user)->getJson('/api/v1/wholemap')
            ->assertOk()
            ->assertJsonCount(2, 'data.signatures')
            ->assertJsonCount(1, 'data.connections')
            ->assertJsonPath('data.connections.0.life', 'critical')
            ->assertJsonPath('data.notes.0.status', 'hostile')
            ->assertJsonCount(2, 'data.systems')
            ->assertJsonFragment(['system_id' => 30000142, 'system_name' => 'Jita'])
            ->assertJsonMissing(['system_name' => 'J999999'])
            ->assertJsonMissing(['system_name' => 'J777777']);

        // The system filter narrows signatures and notes but not the chain.
        $this->actingAs($user)->getJson('/api/v1/wholemap?system_id=30000142')
            ->assertOk()
            ->assertJsonCount(1, 'data.signatures')
            ->assertJsonCount(0, 'data.notes')
            ->assertJsonCount(1, 'data.connections');
    }

    public function test_signature_carries_its_connection(): void
    {
        $user = $this->makeUser();
        $sig = $this->signature($user->id, 31000005, 'J123456');
        WormholeConnection::create(['user_id' => $user->id, 'signature_id' => $sig->id, 'from_system_id' => 31000005, 'from_system_name' => 'J123456']);

        $this->actingAs($user)->getJson('/api/v1/wholemap')
            ->assertOk()
            ->assertJsonPath('data.signatures.0.connection.from_system_name', 'J123456');
    }
}
