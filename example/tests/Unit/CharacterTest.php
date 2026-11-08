<?php

namespace Tests\Unit;

use App\Models\Character;
use Tests\TestCase;

class CharacterTest extends TestCase
{
    private function makeCharacter(array $scopes = []): Character
    {
        return new Character([
            'character_id' => 12345,
            'user_id' => 1,
            'name' => 'Test Char',
            'scopes' => $scopes,
        ]);
    }

    public function test_has_scope_returns_true_when_scope_is_present(): void
    {
        $character = $this->makeCharacter(['esi-skills.read_skills.v1', 'esi-industry.read_character_jobs.v1']);

        $this->assertTrue($character->hasScope('esi-skills.read_skills.v1'));
        $this->assertTrue($character->hasScope('esi-industry.read_character_jobs.v1'));
    }

    public function test_has_scope_returns_false_when_scope_is_absent(): void
    {
        $character = $this->makeCharacter(['esi-skills.read_skills.v1']);

        $this->assertFalse($character->hasScope('esi-industry.read_character_jobs.v1'));
    }

    public function test_has_scope_returns_false_when_scopes_are_null(): void
    {
        $character = new Character([
            'character_id' => 12345,
            'user_id' => 1,
            'name' => 'Test Char',
            'scopes' => null,
        ]);

        $this->assertFalse($character->hasScope('esi-skills.read_skills.v1'));
    }

    public function test_has_scope_returns_false_when_scopes_are_empty(): void
    {
        $character = $this->makeCharacter([]);

        $this->assertFalse($character->hasScope('esi-skills.read_skills.v1'));
    }

    public function test_has_role_returns_true_when_role_is_present(): void
    {
        $character = new Character([
            'character_id' => 12345,
            'user_id' => 1,
            'name' => 'Test Char',
            'roles' => ['Director', 'Accountant'],
        ]);

        $this->assertTrue($character->hasRole('Director'));
        $this->assertTrue($character->hasRole('Accountant'));
    }

    public function test_has_role_returns_false_when_role_is_absent(): void
    {
        $character = new Character([
            'character_id' => 12345,
            'user_id' => 1,
            'name' => 'Test Char',
            'roles' => ['Accountant'],
        ]);

        $this->assertFalse($character->hasRole('Director'));
    }

    public function test_has_role_returns_false_when_roles_are_null(): void
    {
        $character = new Character([
            'character_id' => 12345,
            'user_id' => 1,
            'name' => 'Test Char',
            'roles' => null,
        ]);

        $this->assertFalse($character->hasRole('Director'));
    }
}
