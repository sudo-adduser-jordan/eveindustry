<?php

namespace Tests\Feature\LegacyApi\Heavy;

use App\Models\BuildProgram;
use App\Models\Character;
use App\Models\CorporationBuildProgram;
use App\Models\CorporationBuildProgramConfig;
use App\Models\EveType;
use App\Models\User;
use App\Services\EsiService;
use Mockery\MockInterface;

/**
 * Shared fixtures for the heavy-pages group's legacy and v1 feature tests.
 */
trait HeavyFixtures
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
     * Fake EsiService; every call a test does not expect fails the test.
     */
    protected function mockEsi(): MockInterface
    {
        return $this->mock(EsiService::class);
    }

    protected function asset(array $attrs): array
    {
        return array_merge([
            'item_id' => 1,
            'type_id' => 34,
            'quantity' => 1,
            'location_id' => 60003760,
            'location_type' => 'station',
        ], $attrs);
    }

    /**
     * A one-step program: 10 Tritanium-built things ("Widget" type 5000) from a
     * blueprint, needing 100 Tritanium (type 34) and one child step.
     */
    protected function programSteps(): array
    {
        return [[
            'type_id' => 5000,
            'name' => 'Widget',
            'blueprint_type_id' => 5001,
            'activity_type' => 'manufacturing',
            'quantity_required' => 10,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
                ['type_id' => 5100, 'name' => 'Gadget', 'quantity' => 4],
            ],
            'children' => [[
                'type_id' => 5100,
                'name' => 'Gadget',
                'blueprint_type_id' => 5101,
                'activity_type' => 'manufacturing',
                'quantity_required' => 4,
                'materials' => [['type_id' => 35, 'name' => 'Pyerite', 'quantity' => 40]],
                'children' => [],
            ]],
        ]];
    }

    protected function makeProgram(User $user, array $attrs = []): BuildProgram
    {
        return BuildProgram::create(array_merge([
            'user_id' => $user->id,
            'name' => 'My program',
            'program_type' => 'manufacturing',
            'steps' => $this->programSteps(),
            'settings' => [],
        ], $attrs));
    }

    protected function makeCorpProgram(int $corporationId, array $attrs = []): CorporationBuildProgram
    {
        return CorporationBuildProgram::create(array_merge([
            'corporation_id' => $corporationId,
            'created_by_user_id' => $this->makeUser()->id,
            'name' => 'Corp program',
            'program_type' => 'manufacturing',
            'steps' => $this->programSteps(),
            'settings' => [],
        ], $attrs));
    }

    protected function enableCorpPrograms(Character $director, int $userId): CorporationBuildProgramConfig
    {
        return CorporationBuildProgramConfig::create([
            'corporation_id' => $director->corporation_id,
            'corporation_name' => $director->corporation_name,
            'character_id' => $director->character_id,
            'enabled_by_user_id' => $userId,
            'is_active' => true,
        ]);
    }

    protected function seedTypes(): void
    {
        foreach ([[34, 'Tritanium', 18], [35, 'Pyerite', 18], [5001, 'Widget Blueprint', 9], [5101, 'Gadget Blueprint', 9]] as [$id, $name, $group]) {
            EveType::forceCreate(['type_id' => $id, 'name' => $name, 'group_id' => $group, 'volume' => 0.01]);
        }
    }
}
