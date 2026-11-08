<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\User;
use App\Services\CharacterManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class CharacterManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_main_change_rolls_back_the_previous_main(): void
    {
        $user = User::create(['name' => 'Pilot', 'email' => 'p@test.com', 'is_active' => true]);
        $first = Character::create(['character_id' => 101, 'user_id' => $user->id, 'name' => 'Main', 'corporation_id' => 1, 'is_main' => true]);
        $second = Character::create(['character_id' => 102, 'user_id' => $user->id, 'name' => 'Alt', 'corporation_id' => 1]);
        $event = 'eloquent.updating: '.Character::class;
        Event::listen($event, fn () => throw new RuntimeException('Simulated write failure'));
        try {
            app(CharacterManagementService::class)->setMain($user, $second);
            $this->fail('Expected the write to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated write failure', $e->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertTrue($first->fresh()->is_main);
        $this->assertFalse($second->fresh()->is_main);
    }
}
