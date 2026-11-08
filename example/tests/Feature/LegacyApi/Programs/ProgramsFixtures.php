<?php

namespace Tests\Feature\LegacyApi\Programs;

use App\Models\BlueprintActivity;
use App\Models\BuildProgram;
use App\Models\Character;
use App\Models\CorporationBuildProgram;
use App\Models\CorporationBuildProgramConfig;
use App\Models\User;

/**
 * Shared fixtures for the programs group's legacy and v1 feature tests.
 */
trait ProgramsFixtures
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

    /**
     * A user whose corporation (98000001) has corporate build programs
     * enabled. $role: 'director' (is_director_ceo), 'factory' (Factory_Manager
     * role) or 'member' (plain member, cannot manage).
     */
    protected function corpUser(string $role = 'director', int $corporationId = 98000001): User
    {
        $user = $this->makeUser();
        $attrs = ['corporation_id' => $corporationId];
        if ($role === 'director') {
            $attrs['is_director_ceo'] = true;
        } elseif ($role === 'factory') {
            $attrs['roles'] = ['Factory_Manager'];
        }
        $character = $this->makeCharacter($user, $attrs);

        CorporationBuildProgramConfig::updateOrCreate(
            ['corporation_id' => $corporationId],
            [
                'corporation_name' => 'Test Corp',
                'character_id' => $character->character_id,
                'enabled_by_user_id' => $user->id,
                'is_active' => true,
            ],
        );

        return $user;
    }

    protected function personalProgram(User $user, array $attrs = []): BuildProgram
    {
        return BuildProgram::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Rifters',
            'program_type' => 'manufacturing',
            'steps' => [['type_id' => 587, 'name' => 'Rifter', 'quantity_required' => 2, 'children' => []]],
            'settings' => ['me' => 10],
        ], $attrs));
    }

    protected function corpProgram(int $corporationId = 98000001, array $attrs = []): CorporationBuildProgram
    {
        return CorporationBuildProgram::create(array_merge([
            'corporation_id' => $corporationId,
            'created_by_user_id' => $this->makeUser()->id,
            'name' => 'Corp Rifters',
            'program_type' => 'manufacturing',
            'steps' => [['type_id' => 587, 'name' => 'Rifter', 'quantity_required' => 2, 'children' => []]],
            'settings' => ['me' => 10],
        ], $attrs));
    }

    /**
     * A manufacturing blueprint (id 1000) for Rifter (587) and a reaction
     * formula (id 2000) producing 100 units of Fullerene Fuel Block-ish
     * product 4312 from one input.
     */
    protected function seedBlueprints(): void
    {
        BlueprintActivity::create([
            'blueprint_type_id' => 1000,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 100,
            'materials' => [['type_id' => 34, 'quantity' => 10]],
            'products' => [['type_id' => 587, 'quantity' => 1]],
        ]);
        BlueprintActivity::create([
            'blueprint_type_id' => 2000,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 100,
            'materials' => [['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100]],
            'products' => [['type_id' => 4312, 'name' => 'Nitrogen Fuel Block Thing', 'quantity' => 10]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function storePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'My Program',
            't2_type_id' => 587,
            't2_type_name' => 'Rifter',
            'steps' => [
                ['type_id' => 587, 'name' => 'Rifter', 'quantity_required' => 3, 'runs_needed' => 3],
            ],
            'settings' => ['me' => 10],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    protected function reactionPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Reaction Program',
            'entries' => [['formula_type_id' => 2000, 'formula_name' => 'Formula', 'slots' => 2, 'runs_per_slot' => 5]],
            'settings' => ['reaction_structure_type' => 'tatara', 'reaction_rig_me' => 'none', 'reaction_system_security' => 'lowsec'],
        ], $overrides);
    }
}
