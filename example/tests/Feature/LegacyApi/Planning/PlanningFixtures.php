<?php

namespace Tests\Feature\LegacyApi\Planning;

use App\Models\Character;
use App\Models\MoonScanResult;
use App\Models\MoonScanSystem;
use App\Models\StationConfig;
use App\Models\User;
use App\Models\WholemapSignature;
use App\Models\WormholeConnection;

/**
 * Shared fixtures for the planning group's legacy and v1 feature tests.
 */
trait PlanningFixtures
{
    protected function makeUser(array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'Pilot',
            'email' => 'p'.uniqid().'@test.com',
            'is_active' => true,
        ], $attrs));
    }

    protected function makeCharacter(User $user, array $attrs = []): Character
    {
        return Character::create(array_merge([
            'character_id' => random_int(90000000, 99999999),
            'user_id' => $user->id,
            'name' => 'Main Pilot',
            'corporation_id' => 98000001,
            'corporation_name' => 'Test Corp',
            'is_main' => true,
            'access_token' => 'secret-access-token',
            'refresh_token' => 'secret-refresh-token',
            'token_expires_at' => now()->addHour(),
            'scopes' => [],
        ], $attrs));
    }

    protected function makeSignature(User $user, array $attrs = []): WholemapSignature
    {
        return WholemapSignature::create(array_merge([
            'user_id' => $user->id,
            'signature_id' => 'ABC-123',
            'system_id' => 31000005,
            'system_name' => 'J100000',
            'type' => 'wormhole',
            'created_by' => $user->name,
            'updated_by' => $user->name,
        ], $attrs));
    }

    protected function makeConnection(User $user, ?WholemapSignature $sig = null, array $attrs = []): WormholeConnection
    {
        $sig ??= $this->makeSignature($user);

        return WormholeConnection::create(array_merge([
            'user_id' => $user->id,
            'signature_id' => $sig->id,
            'from_system_id' => 31000005,
            'from_system_name' => 'J100000',
        ], $attrs));
    }

    protected function makeStationConfig(User $user, array $attrs = []): StationConfig
    {
        return StationConfig::create(array_merge([
            'user_id' => $user->id,
            'activity_type' => 'manufacturing',
            'name' => 'Home Raitaru',
            'structure_type' => 'raitaru',
            'rig_me' => 't1',
            'rig_te' => 'none',
            'system_security' => 'highsec',
        ], $attrs));
    }

    protected function makeMoon(int $corporationId = 98000001, array $attrs = []): MoonScanResult
    {
        $system = MoonScanSystem::create([
            'system_name' => 'Jita',
            'corporation_id' => $corporationId,
            'region_id' => 10000002,
            'pricing_mode' => 'sell',
        ]);

        return MoonScanResult::create(array_merge([
            'system_id' => $system->id,
            'moon_name' => 'Jita IV - Moon 1',
            'planet_name' => 'Jita IV',
            'grade' => 'A',
            'ores' => [],
            'total_value' => 100,
        ], $attrs));
    }

    /** @return array<string, mixed> */
    protected function inventionPlanPayload(array $over = []): array
    {
        return array_merge(['name' => 'Ishtar', 't2_type_id' => 12005, 't2_type_name' => 'Ishtar'], $over);
    }

    /** @return array<string, mixed> */
    protected function reactionPlanPayload(array $over = []): array
    {
        return array_merge([
            'name' => 'Fullerides',
            'entries' => [['formula_type_id' => 1, 'formula_name' => 'Fulleride', 'slots' => 2, 'runs_per_slot' => 10]],
        ], $over);
    }
}
